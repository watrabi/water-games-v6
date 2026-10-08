# runs the AI's code in a throwaway docker container, one per run. php calls this over localhost;
# it's the only thing that touches docker, so the website itself never gets docker (which is root).
#
#   POST /run  {"workspace": "c123", "language": "python"|"bash", "code": "..."}
#   header     Authorization: Bearer $SANDBOX_TOKEN
#
# every run: fresh container, not root, no capabilities, read-only system, 512 MB, 1 cpu, 128 processes,
# killed after RUN_SECONDS. the chat's folder is mounted at /work and kept between runs for a week.
# it can reach the internet but not the home network or this server, see ensure_firewall()

import hmac, json, os, re, shutil, subprocess, threading, time, uuid
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

TOKEN = os.environ.get("SANDBOX_TOKEN", "")
PORT = int(os.environ.get("SANDBOX_PORT", "3003"))
IMAGE = os.environ.get("SANDBOX_IMAGE", "watr-sandbox:latest")
WORK_ROOT = os.environ.get("SANDBOX_WORK", "/var/lib/watr-sandbox/work")
RUN_SECONDS = int(os.environ.get("SANDBOX_RUN_SECONDS", "60"))
MAX_RUNNING = int(os.environ.get("SANDBOX_MAX_RUNNING", "3"))
WORKSPACE_MB = 200
OUTPUT_BYTES = 16000
KEEP_DAYS = 7
UID = 10001

NETWORK = "watr-sandbox"
BRIDGE = "watrsbx0"
# home network, this server, tailscale, link-local and cloud metadata
BLOCKED = ["10.0.0.0/8", "172.16.0.0/12", "192.168.0.0/16", "100.64.0.0/10", "169.254.0.0/16", "127.0.0.0/8", "0.0.0.0/8"]
# mail, so nobody sends spam from this address
BLOCKED_PORTS = ["25", "465", "587"]

running = threading.BoundedSemaphore(MAX_RUNNING)
firewall_lock = threading.Lock()


def sh(*args, **kwargs):
    return subprocess.run(list(args), capture_output=True, text=True, **kwargs)


def ensure_network():
    if sh("docker", "network", "inspect", NETWORK).returncode != 0:
        sh("docker", "network", "create", "--driver", "bridge",
           "-o", "com.docker.network.bridge.name=" + BRIDGE,
           "-o", "com.docker.network.bridge.enable_icc=false", NETWORK, check=True)


def ensure_rule(chain, rule):
    if sh("iptables", "-C", chain, *rule).returncode != 0:
        sh("iptables", "-I", chain, "1", *rule, check=True)


# checked before every run, since a docker or ufw restart can drop them. no rules, no run
def ensure_firewall():
    with firewall_lock:
        ensure_network()
        # nothing from a container to this server itself (mysql, aaPanel, ollama). its internet traffic is
        # forwarded, not input, and docker's dns answers inside the container, so this doesn't touch either
        ensure_rule("INPUT", ["-i", BRIDGE, "-j", "DROP"])
        for port in BLOCKED_PORTS:
            ensure_rule("DOCKER-USER", ["-i", BRIDGE, "-p", "tcp", "--dport", port, "-j", "DROP"])
        for net in BLOCKED:
            ensure_rule("DOCKER-USER", ["-i", BRIDGE, "-d", net, "-j", "DROP"])


def folder_mb(path):
    total = 0
    for root, dirs, files in os.walk(path):
        for name in files:
            try:
                total += os.lstat(os.path.join(root, name)).st_size
            except OSError:
                pass
    return total / 1048576


def list_files(path):
    out = []
    for name in sorted(os.listdir(path)):
        if name.startswith("."):
            continue
        full = os.path.join(path, name)
        if os.path.isdir(full):
            out.append(name + "/")
        else:
            out.append("%s (%d bytes)" % (name, os.lstat(full).st_size))
    return out[:50]


def cut(data):
    text = data[:OUTPUT_BYTES].decode("utf-8", "replace")
    if len(data) > OUTPUT_BYTES:
        text += "\n[output cut off after %d bytes]" % OUTPUT_BYTES
    return text


def run(workspace, language, code):
    ensure_firewall()

    folder = os.path.join(WORK_ROOT, workspace)
    os.makedirs(folder, exist_ok=True)
    os.chown(folder, UID, UID)
    os.utime(folder)

    used = folder_mb(folder)
    if used > WORKSPACE_MB:
        return {"ok": False, "error": "This chat's sandbox folder is full (%d MB of %d MB). Delete some files first, e.g. with bash: rm -rf bigfolder" % (used, WORKSPACE_MB)}

    name = "watr-sbx-" + uuid.uuid4().hex[:12]
    command = ["python3", "-"] if language == "python" else ["bash", "-s"]
    args = [
        "docker", "run", "--rm", "-i", "--name", name,
        "--network", NETWORK,
        "--user", "%d:%d" % (UID, UID),
        "--cap-drop", "ALL", "--security-opt", "no-new-privileges",
        "--read-only", "--tmpfs", "/tmp:rw,nosuid,size=64m",
        "--memory", "512m", "--memory-swap", "512m", "--cpus", "1", "--pids-limit", "128",
        "--ulimit", "fsize=104857600", "--ulimit", "nofile=256",
        "-v", folder + ":/work", "-w", "/work",
        IMAGE, *command,
    ]

    started = time.time()
    proc = subprocess.Popen(args, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    timed_out = False
    try:
        out, err = proc.communicate(code.encode("utf-8"), timeout=RUN_SECONDS)
    except subprocess.TimeoutExpired:
        timed_out = True
        sh("docker", "kill", name)
        out, err = proc.communicate()

    return {
        "ok": True,
        "exit_code": proc.returncode,
        "timed_out": timed_out,
        "seconds": round(time.time() - started, 1),
        "stdout": cut(out),
        "stderr": cut(err),
        "files": list_files(folder),
    }


# chats nobody has run code in for a week lose their folder
def prune():
    while True:
        cutoff = time.time() - KEEP_DAYS * 86400
        try:
            for name in os.listdir(WORK_ROOT):
                path = os.path.join(WORK_ROOT, name)
                if os.path.isdir(path) and os.stat(path).st_mtime < cutoff:
                    shutil.rmtree(path, ignore_errors=True)
        except OSError:
            pass
        time.sleep(3600)


class Handler(BaseHTTPRequestHandler):
    def answer(self, status, body):
        data = json.dumps(body).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def do_POST(self):
        if self.path != "/run":
            return self.answer(404, {"ok": False, "error": "not found"})
        if not TOKEN or not hmac.compare_digest(self.headers.get("Authorization", ""), "Bearer " + TOKEN):
            return self.answer(403, {"ok": False, "error": "bad token"})

        try:
            length = int(self.headers.get("Content-Length", "0"))
            if length > 200000:
                return self.answer(413, {"ok": False, "error": "That code is too long."})
            request = json.loads(self.rfile.read(length))
            workspace = str(request.get("workspace", ""))
            language = str(request.get("language", "python"))
            code = str(request.get("code", ""))
        except (ValueError, TypeError):
            return self.answer(400, {"ok": False, "error": "bad request"})

        if not re.fullmatch(r"[a-z0-9-]{1,64}", workspace) or language not in ("python", "bash") or not code.strip():
            return self.answer(400, {"ok": False, "error": "bad request"})

        if not running.acquire(timeout=RUN_SECONDS):
            return self.answer(503, {"ok": False, "error": "The sandbox is busy, try again in a minute."})
        try:
            self.answer(200, run(workspace, language, code))
        except Exception as e:
            self.answer(500, {"ok": False, "error": "The sandbox failed: %s" % e})
        finally:
            running.release()

    def log_message(self, fmt, *args):
        pass


if __name__ == "__main__":
    if not TOKEN:
        raise SystemExit("SANDBOX_TOKEN isn't set")
    os.makedirs(WORK_ROOT, exist_ok=True)
    ensure_firewall()
    threading.Thread(target=prune, daemon=True).start()
    ThreadingHTTPServer(("127.0.0.1", PORT), Handler).serve_forever()
