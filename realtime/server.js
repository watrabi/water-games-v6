// websocket push for Water Games chat.
//
// PHP does all the real work (saving messages, checking friendships) and then POSTs to /publish here,
// which hands the event to every open tab of the people it's for. browsers connect with a short lived
// token PHP signs with REALTIME_SECRET, so this never needs the database.
//
// env:
//   REALTIME_SECRET   shared with PHP (.env), at least 16 characters
//   PORT              default 3001
//   HOST              default 127.0.0.1 (put nginx / apache in front for wss://)
//   ALLOWED_ORIGINS   comma separated, e.g. https://watr.lol. empty = don't check

const http = require("http");
const crypto = require("crypto");
const { WebSocketServer } = require("ws");

const SECRET = process.env.REALTIME_SECRET || "";
const PORT = Number(process.env.PORT || 3001);
const HOST = process.env.HOST || "127.0.0.1";
const ORIGINS = (process.env.ALLOWED_ORIGINS || "").split(",").map(s => s.trim()).filter(Boolean);

const MAX_SOCKETS_PER_USER = 10;
const TYPING_EVERY_MS = 1500;

if(SECRET.length < 16){
    console.error("REALTIME_SECRET needs to be set (16+ characters, the same value as in the site's .env)");
    process.exit(1);
}

const users = new Map(); // user id -> Set of sockets

function log(...args){
    console.log(new Date().toISOString(), ...args);
}

function sameSecret(given){
    let a = Buffer.from(String(given || ""));
    let b = Buffer.from(SECRET);
    return a.length === b.length && crypto.timingSafeEqual(a, b);
}

// token: "<userId>.<expires>.<hex hmac of 'userId.expires'>"
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

function sendTo(userId, payload){
    let sockets = users.get(Number(userId));
    if(!sockets){
        return 0;
    }

    let data = JSON.stringify(payload);
    let sent = 0;
    for(let socket of sockets){
        if(socket.readyState === socket.OPEN){
            socket.send(data);
            sent++;
        }
    }
    return sent;
}

function json(res, status, body){
    res.writeHead(status, { "Content-Type": "application/json" });
    res.end(JSON.stringify(body));
}

// ---------- internal http api (PHP talks to this) ----------

const server = http.createServer(function(req, res) {
    let url = new URL(req.url, "http://localhost");

    if(req.method === "GET" && url.pathname === "/health"){
        return json(res, 200, { ok: true, users: users.size });
    }

    if(!sameSecret(req.headers["x-realtime-secret"])){
        return json(res, 404, { error: "not found" });
    }

    // who has a socket open right now, for the online dots
    if(req.method === "GET" && url.pathname === "/online"){
        return json(res, 200, { online: Array.from(users.keys()) });
    }

    if(req.method === "POST" && url.pathname === "/publish"){
        let body = "";
        req.on("data", function(chunk) {
            body += chunk;
            if(body.length > 256 * 1024){
                req.destroy();
            }
        });
        req.on("end", function() {
            let message;
            try {
                message = JSON.parse(body);
            } catch (e) {
                return json(res, 400, { error: "bad json" });
            }

            if(!Array.isArray(message.to) || !message.event || typeof message.event.type !== "string"){
                return json(res, 400, { error: "need to[] and event.type" });
            }

            let delivered = 0;
            new Set(message.to.map(Number)).forEach(id => { delivered += sendTo(id, message.event); });
            json(res, 200, { delivered: delivered });
        });
        return;
    }

    json(res, 404, { error: "not found" });
});

// ---------- websockets (browsers talk to this) ----------

const wss = new WebSocketServer({ noServer: true, maxPayload: 4096 });

server.on("upgrade", function(req, socket, head) {
    let url = new URL(req.url, "http://localhost");
    let userId = verifyToken(url.searchParams.get("token"));
    let origin = req.headers.origin || "";

    if(!userId || (ORIGINS.length && !ORIGINS.includes(origin))){
        socket.write("HTTP/1.1 401 Unauthorized\r\nConnection: close\r\n\r\n");
        socket.destroy();
        return;
    }

    wss.handleUpgrade(req, socket, head, function(ws) {
        wss.emit("connection", ws, userId);
    });
});

wss.on("connection", function(ws, userId) {
    ws.userId = userId;
    ws.alive = true;
    ws.lastTyping = 0;

    let sockets = users.get(userId) || new Set();
    users.set(userId, sockets);
    sockets.add(ws);

    // someone with a pile of tabs: drop the oldest
    if(sockets.size > MAX_SOCKETS_PER_USER){
        let oldest = sockets.values().next().value;
        oldest.close(4002, "too many connections");
    }

    ws.send(JSON.stringify({ type: "hello" }));

    ws.on("pong", () => { ws.alive = true; });

    ws.on("message", function(raw) {
        let message;
        try {
            message = JSON.parse(raw);
        } catch (e) {
            return;
        }

        // "typing..." goes straight to the other person. clients only show it for an open chat with a
        // friend, so there's nothing to gain by sending it to strangers
        if(message.type === "typing" && Number.isInteger(message.to) && message.to !== userId){
            let now = Date.now();
            if(now - ws.lastTyping >= TYPING_EVERY_MS){
                ws.lastTyping = now;
                sendTo(message.to, { type: "typing", from: userId });
            }
        }

        // in a group the browser lists who's in it (node has no database to check). each client only shows it
        // for a group it's in, from someone who's a member, so a made up list can't put words anywhere
        if(message.type === "typing" && Number.isInteger(message.group) && Array.isArray(message.to)){
            let now = Date.now();
            if(now - ws.lastTyping >= TYPING_EVERY_MS){
                ws.lastTyping = now;
                message.to.slice(0, 20).filter(id => Number.isInteger(id) && id !== userId).forEach(function(id) {
                    sendTo(id, { type: "typing", from: userId, group: message.group });
                });
            }
        }
    });

    ws.on("close", function() {
        sockets.delete(ws);
        if(!sockets.size){
            users.delete(userId);
        }
    });

    ws.on("error", () => {});
});

// drop connections that stopped answering (sleeping laptops, dead wifi)
const heartbeat = setInterval(function() {
    wss.clients.forEach(function(ws) {
        if(!ws.alive){
            return ws.terminate();
        }
        ws.alive = false;
        ws.ping();
    });
}, 30000);

server.listen(PORT, HOST, () => log(`realtime listening on ${HOST}:${PORT}`));

function shutdown(){
    log("shutting down");
    clearInterval(heartbeat);
    wss.clients.forEach(ws => ws.close(1001, "server restarting"));
    server.close(() => process.exit(0));
    setTimeout(() => process.exit(0), 3000);
}

process.on("SIGTERM", shutdown);
process.on("SIGINT", shutdown);
