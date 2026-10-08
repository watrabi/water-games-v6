// web proxy for Water Games (/proxy on the site).
//
// the proxying itself happens in the browser: Scramjet's service worker rewrites every page, script and request a
// proxied site makes, and the transport (epoxy or libcurl, compiled to wasm) does the TLS itself. all this server
// does is hand out those files and run a Wisp server, which turns one websocket per browser into many plain TCP
// connections. so it never sees inside https traffic and has very little work per request, which is why it's fast.
//
// browsers connect to /proxy/wisp/<token>/ with a token PHP signs with PROXY_SECRET (same format as realtime/), so
// only signed in people can use it, and never to private or loopback addresses (no reaching the database or
// aaPanel through it).
//
// env:
//   PROXY_SECRET          shared with PHP (.env), at least 16 characters
//   PORT                  default 3002
//   HOST                  default 127.0.0.1 (put nginx / apache in front, see the readme)
//   ALLOWED_ORIGINS       comma separated, e.g. https://watr.lol. empty = don't check
//   PROXY_DNS             DNS servers, default "1.1.1.3,1.0.0.3" (Cloudflare's malware + adult filter).
//                         "system" uses the server's own resolver
//   PROXY_PORTS           ports sites may be reached on, default "80,443,8080,8443". keeps mail (25/465/587) and
//                         other services out
//   PROXY_BLOCKED_HOSTS   comma separated domains to refuse (subdomains too), e.g. "example.com,example.org"
//   PROXY_MAX_PER_USER    websockets one account can have open, default 8
//   DEV_UPSTREAM          local dev only: send everything outside /proxy/ here (e.g. http://127.0.0.1:8000), so
//                         the site and the proxy share an origin without nginx

const http = require("http");
const crypto = require("crypto");
const fs = require("fs");
const path = require("path");
const zlib = require("zlib");
const { server: wisp, logging } = require("@mercuryworkshop/wisp-js/server");

const SECRET = process.env.PROXY_SECRET || "";
const PORT = Number(process.env.PORT || 3002);
const HOST = process.env.HOST || "127.0.0.1";
const ORIGINS = list(process.env.ALLOWED_ORIGINS);
const DNS = process.env.PROXY_DNS === undefined ? ["1.1.1.3", "1.0.0.3"] : list(process.env.PROXY_DNS);
const PORTS = list(process.env.PROXY_PORTS || "80,443,8080,8443").map(Number).filter(n => n > 0 && n < 65536);
const BLOCKED = list(process.env.PROXY_BLOCKED_HOSTS).map(d => d.toLowerCase().replace(/^\*?\./, ""));
const MAX_PER_USER = Number(process.env.PROXY_MAX_PER_USER || 8);
const DEV_UPSTREAM = process.env.DEV_UPSTREAM || "";

if(SECRET.length < 16){
    console.error("PROXY_SECRET needs to be set (16+ characters, the same value as in the site's .env)");
    process.exit(1);
}

function list(value){
    return String(value || "").split(",").map(s => s.trim()).filter(Boolean);
}

function log(...args){
    console.log(new Date().toISOString(), ...args);
}

// ---------- wisp ----------

logging.set_level(process.env.PROXY_DEBUG ? logging.DEBUG : logging.WARN);
Object.assign(wisp.options, {
    allow_udp_streams: false,      // nothing in a browser needs it, and it's what gets abused
    allow_private_ips: false,
    allow_loopback_ips: false,
    port_whitelist: PORTS,
    hostname_blacklist: BLOCKED.length ? BLOCKED.map(d => new RegExp("(^|\\.)" + d.replace(/[.*+?^${}()|[\]\\]/g, "\\$&") + "$", "i")) : null,
    stream_limit_total: 400,       // a heavy page opens ~100, this only stops runaway scripts
    dns_ttl: 600,                  // the cache is what makes repeat visits to a site skip DNS entirely
    dns_result_order: "ipv4first", // a site with broken IPv6 otherwise costs a timeout before it falls back
    wisp_version: 2,
});
if(DNS.length && DNS[0] !== "system"){
    wisp.options.dns_method = "resolve";
    wisp.options.dns_servers = DNS;
}

// ---------- static files ----------
// everything the browser needs, kept in memory already compressed. brotli at its smallest setting takes ~10s for
// these (the wasm transports are ~2MB raw, ~600KB as brotli), so the results are saved in node_modules/.watr-cache
// and later starts just read them. npm install clears that folder, which is exactly when the files change

const VERSION = crypto.createHash("md5").update(fs.readFileSync(path.join(__dirname, "package-lock.json"))).digest("hex").slice(0, 10);
const CACHE = path.join(__dirname, "node_modules", ".watr-cache");

// straight from node_modules: some of these packages don't export their dist files
function pkg(name, file){
    return path.join(__dirname, "node_modules", name, file);
}

const sources = {
    "/proxy/s/scram/scramjet.all.js": pkg("@mercuryworkshop/scramjet", "dist/scramjet.all.js"),
    "/proxy/s/scram/scramjet.sync.js": pkg("@mercuryworkshop/scramjet", "dist/scramjet.sync.js"),
    "/proxy/s/scram/scramjet.wasm.wasm": pkg("@mercuryworkshop/scramjet", "dist/scramjet.wasm.wasm"),
    "/proxy/s/baremux/index.js": pkg("@mercuryworkshop/bare-mux", "dist/index.js"),
    "/proxy/s/baremux/worker.js": pkg("@mercuryworkshop/bare-mux", "dist/worker.js"),
    "/proxy/s/epoxy/index.mjs": pkg("@mercuryworkshop/epoxy-transport", "dist/index.mjs"),
    "/proxy/s/libcurl/index.mjs": pkg("@mercuryworkshop/libcurl-transport", "dist/index.mjs"),
};

const types = { ".js": "text/javascript; charset=utf-8", ".mjs": "text/javascript; charset=utf-8", ".wasm": "application/wasm", ".html": "text/html; charset=utf-8" };

const brotli = require("util").promisify(zlib.brotliCompress);
const gzip = require("util").promisify(zlib.gzip);

// zlib's async functions run on node's thread pool, so all the files compress at once
async function prepare(raw, type, extra){
    let hash = crypto.createHash("sha1").update(raw).digest("base64url").slice(0, 16);
    let cached = path.join(CACHE, hash);
    let br, gz;
    try {
        [br, gz] = await Promise.all([fs.promises.readFile(cached + ".br"), fs.promises.readFile(cached + ".gz")]);
    } catch (e) {
        [br, gz] = await Promise.all([
            brotli(raw, { params: { [zlib.constants.BROTLI_PARAM_QUALITY]: 11, [zlib.constants.BROTLI_PARAM_SIZE_HINT]: raw.length } }),
            gzip(raw, { level: 9 }),
        ]);
        try {
            await fs.promises.mkdir(CACHE, { recursive: true });
            await Promise.all([fs.promises.writeFile(cached + ".br", br), fs.promises.writeFile(cached + ".gz", gz)]);
        } catch (e) {} // read only disk: fine, it just compresses again next time
    }
    return Object.assign({ raw: raw, br: br, gzip: gz, etag: '"' + hash + '"', type: type }, extra);
}

const files = new Map();

async function loadFiles(){
    let jobs = Object.entries(sources).map(async ([url, file]) => {
        files.set(url, await prepare(await fs.promises.readFile(file), types[path.extname(file)]));
    });

    // the service worker, with this build's version on the script it imports, so that can be cached for good
    let sw = fs.readFileSync(path.join(__dirname, "public", "sw.js"), "utf8").replace(/__VERSION__/g, VERSION);
    jobs.push(prepare(Buffer.from(sw), types[".js"], { sw: true }).then(f => files.set("/proxy/sw.js", f)));

    // what a proxied address shows when the service worker didn't catch it (first visit, or a hard refresh skipped it)
    jobs.push(prepare(fs.readFileSync(path.join(__dirname, "public", "boot.html")), types[".html"], { page: true }).then(f => files.set("/proxy/~/", f)));

    await Promise.all(jobs);
}

function sendFile(req, res, file, versioned){
    let headers = {
        "Content-Type": file.type,
        "ETag": file.etag,
        "Vary": "Accept-Encoding",
        "X-Content-Type-Options": "nosniff",
    };

    if(file.sw){
        headers["Cache-Control"] = "no-cache";
        headers["Service-Worker-Allowed"] = "/proxy/";
    } else if(file.page){
        headers["Cache-Control"] = "no-store";
    } else {
        // ?v= is the package-lock hash, so a versioned url never changes. without it, check back hourly
        headers["Cache-Control"] = versioned ? "public, max-age=31536000, immutable" : "public, max-age=3600, stale-while-revalidate=86400";
    }

    if(req.headers["if-none-match"] === file.etag){
        res.writeHead(304, headers);
        return res.end();
    }

    let accept = String(req.headers["accept-encoding"] || "");
    let body = file.raw;
    if(/\bbr\b/.test(accept)){
        body = file.br;
        headers["Content-Encoding"] = "br";
    } else if(/\bgzip\b/.test(accept)){
        body = file.gzip;
        headers["Content-Encoding"] = "gzip";
    }

    headers["Content-Length"] = body.length;
    res.writeHead(200, headers);
    res.end(req.method === "HEAD" ? undefined : body);
}

// ---------- tokens ----------

// "<userId>.<expires>.<hex hmac of 'userId.expires'>", signed by PHP (watrlabs\proxy\proxy::clientConfig)
function verifyToken(token){
    let parts = String(token || "").split(".");
    if(parts.length !== 3){
        return null;
    }

    let [id, expires, signature] = parts;
    if(!/^\d+$/.test(id) || !/^\d+$/.test(expires) || Number(expires) < Date.now() / 1000){
        return null;
    }

    let expected = crypto.createHmac("sha256", SECRET).update(id + "." + expires).digest("hex");
    if(signature.length !== expected.length || !crypto.timingSafeEqual(Buffer.from(signature), Buffer.from(expected))){
        return null;
    }

    return Number(id);
}

// ---------- http ----------

const open = new Map(); // user id -> open wisp sockets
let total = 0;

function devForward(req, res){
    let target = new URL(req.url, DEV_UPSTREAM);
    let upstream = http.request(target, { method: req.method, headers: Object.assign({}, req.headers, { host: target.host }) }, function(answer) {
        res.writeHead(answer.statusCode, answer.headers);
        answer.pipe(res);
    });
    upstream.on("error", () => { res.writeHead(502); res.end("dev upstream isn't answering"); });
    req.pipe(upstream);
}

const server = http.createServer(function(req, res) {
    let url = new URL(req.url, "http://localhost");

    if(url.pathname === "/proxy/health" || url.pathname === "/health"){
        res.writeHead(200, { "Content-Type": "application/json", "Cache-Control": "no-store" });
        return res.end(JSON.stringify({ ok: true, connections: total, users: open.size, version: VERSION }));
    }

    if(req.method === "GET" || req.method === "HEAD"){
        let file = files.get(url.pathname);
        if(file){
            return sendFile(req, res, file, url.searchParams.get("v") === VERSION);
        }
        if(url.pathname.startsWith("/proxy/~/")){
            return sendFile(req, res, files.get("/proxy/~/"), false);
        }
    }

    if(DEV_UPSTREAM && !url.pathname.startsWith("/proxy/")){
        return devForward(req, res);
    }

    res.writeHead(404, { "Content-Type": "text/plain" });
    res.end("not found");
});

server.on("upgrade", function(req, socket, head) {
    let match = /^\/proxy\/wisp\/([^/?]+)\/$/.exec(req.url.split("?")[0]);
    let userId = match ? verifyToken(decodeURIComponent(match[1])) : null;
    let origin = req.headers.origin || "";

    if(!userId || (ORIGINS.length && !ORIGINS.includes(origin))){
        socket.write("HTTP/1.1 401 Unauthorized\r\nConnection: close\r\n\r\n");
        return socket.destroy();
    }

    let mine = open.get(userId) || 0;
    if(mine >= MAX_PER_USER){
        socket.write("HTTP/1.1 429 Too Many Requests\r\nConnection: close\r\n\r\n");
        return socket.destroy();
    }

    open.set(userId, mine + 1);
    total++;
    socket.setNoDelay(true); // small frames (TLS handshakes, websocket messages) go out now, not 40ms later
    socket.once("close", function() {
        total--;
        let left = (open.get(userId) || 1) - 1;
        left ? open.set(userId, left) : open.delete(userId);
    });

    // wisp-js reads the path: one ending in "/" is a wisp connection, anything else would be wsproxy (one host
    // per socket, named in the path). only ever wisp here
    req.url = "/";
    wisp.routeRequest(req, socket, head);
});

server.keepAliveTimeout = 65000; // longer than nginx's / cloudflare's, so they never reuse a closed connection
server.headersTimeout = 66000;

let started = Date.now();
loadFiles().then(function() {
    server.listen(PORT, HOST, () => log(`proxy listening on ${HOST}:${PORT} (build ${VERSION}, files ready in ${Date.now() - started}ms, dns ${DNS.join(" ") || "system"}, ports ${PORTS.join(" ")})`));
}, function(error) {
    console.error("couldn't load the proxy's files. did `npm install` run in proxy/?", error);
    process.exit(1);
});

function shutdown(){
    log("shutting down");
    server.close(() => process.exit(0));
    setTimeout(() => process.exit(0), 3000);
}

process.on("SIGTERM", shutdown);
process.on("SIGINT", shutdown);
