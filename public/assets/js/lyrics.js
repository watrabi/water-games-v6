// lyrics for whatever the player has on. synced (LRC) lyrics light up line by line with the song,
// clicking a line jumps there. when the server has a Lyricsfile for the song, its lines also say when they end (so
// instrumental breaks go quiet) and sometimes when each word is sung, which lights up word by word. loaded once like music.js, the panel sits outside #main so it stays put
// across page changes

const lyricsPrefKey = "watrLyrics"; // "open" | "closed", unset means open it the first time we find some

let lyrics = {
    cache: {},         // track id -> {lyrics, synced} once the server answers
    trackId: null,
    lines: [],         // [{time, end, text, words, el}] for synced, [] for plain. end/words only from a Lyricsfile
    active: -1,
    activeKey: "",     // which lines are lit, so nothing is redrawn when that hasn't changed
    frame: null,
    userScrolled: 0    // last time someone scrolled the panel themselves
};

function lyricsPref(){
    try { return localStorage.getItem(lyricsPrefKey); } catch (e) { return null; }
}

function setLyricsPref(value){
    try { localStorage.setItem(lyricsPrefKey, value); } catch (e) {}
}

// "[01:02.50][02:10.00]text" -> one entry per timestamp. honours an [offset:+250] tag (ms, positive = earlier)
function parseLrc(text){
    let lines = [];
    let offset = 0;

    text.split(/\r?\n/).forEach(function(raw) {
        let tag = raw.match(/^\[offset:\s*([+-]?\d+)\s*\]/i);
        if(tag){
            offset = parseInt(tag[1], 10) / 1000;
            return;
        }

        let stamps = [];
        let rest = raw.replace(/\[(\d{1,3}):(\d{1,2}(?:[.:]\d{1,3})?)\]/g, function(_, min, sec) {
            stamps.push(parseInt(min, 10) * 60 + parseFloat(sec.replace(":", ".")));
            return "";
        });

        stamps.forEach(time => lines.push({ time: time, text: rest.trim() }));
    });

    lines.forEach(line => line.time = Math.max(0, line.time - offset));
    return lines.sort((a, b) => a.time - b.time);
}

function panelOpen(){
    return !$("#lyricsPanel").prop("hidden");
}

function setPanel(open, remember){
    $("#lyricsPanel").prop("hidden", !open);
    $("#playerLyrics").attr("aria-pressed", open ? "true" : "false").toggleClass("on", open);
    $("body").toggleClass("lyrics-open", open);
    if(open){
        $("#queuePanel").prop("hidden", true);
        $("#playerQueue").attr("aria-pressed", "false");
    }

    if(remember){
        setLyricsPref(open ? "open" : "closed");
    }

    if(open){
        lyrics.active = -1;
        lyrics.activeKey = "";
        tick();
    }
    loop();
}

function showMessage(text){
    lyrics.lines = [];
    $("#lyricsBody").empty().append($("<p>", { "class": "lyricsEmpty", text: text }));
    $(".lyricsCredit").prop("hidden", true);
}

function render(track, data){
    let body = $("#lyricsBody").empty();
    lyrics.lines = [];
    lyrics.active = -1;
    body.scrollTop(0);

    if(!data || !data.lyrics){
        $("#playerLyrics").addClass("none").attr("title", "No lyrics found for this one");
        showMessage("Couldn't find lyrics for this one.");
        return;
    }

    $("#playerLyrics").removeClass("none").attr("title", "Lyrics");
    $(".lyricsCredit").prop("hidden", false);
    let source = { "lrc.red": ["lrc.red", "https://lrc.red"], lrclib: ["LRCLIB", "https://lrclib.net"] }[data.source] || ["LRCLIB", "https://lrclib.net"];
    $("#lyricsSource").text(source[0]).attr("href", source[1]);
    body.toggleClass("synced", !!(data.synced || data.lines));
    body.toggleClass("words", !!data.words);
    lyrics.activeKey = "";

    if(data.lines && data.lines.length){
        // from the Lyricsfile: times are milliseconds
        lyrics.lines = data.lines.map(function(line) {
            let entry = {
                time: line.start / 1000,
                end: line.end === null ? null : line.end / 1000,
                text: line.text,
                words: (line.words || []).map(w => ({ time: w.start / 1000, end: w.end === null ? null : w.end / 1000, text: w.text }))
            };
            let el = $("<button>", { type: "button", "class": "lyricsLine" + (line.text.trim() ? "" : " gap") }).data("time", entry.time)[0];
            if(entry.words.length){
                entry.words.forEach(function(word) {
                    word.el = $("<span>", { "class": "lyricsWord", text: word.text })[0];
                    el.append(word.el);
                });
            } else {
                el.textContent = line.text.trim() ? line.text : "♪";
            }
            entry.el = el;
            body.append(el);
            return entry;
        });
    } else if(data.synced){
        lyrics.lines = parseLrc(data.lyrics);
        lyrics.lines.forEach(function(line) {
            line.el = $("<button>", {
                type: "button",
                "class": "lyricsLine" + (line.text ? "" : " gap"),
                text: line.text || "♪"
            }).data("time", line.time)[0];
            body.append(line.el);
        });
    } else {
        data.lyrics.split(/\r?\n/).forEach(function(text) {
            body.append($("<p>", { "class": "lyricsLine plain" + (text.trim() ? "" : " break"), text: text }));
        });
    }

    // first time anyone's found lyrics, show them off. after that it remembers what you picked
    if(!panelOpen() && lyricsPref() === null){
        setPanel(true, false);
    }

    tick();
}

function load(track){
    lyrics.trackId = track ? track.id : null;

    if(!track){
        setPanel(false, false);
        return;
    }

    $("#lyricsSong").text(track.title + (track.artist ? " · " + track.artist : ""));

    if(lyrics.cache[track.id]){
        render(track, lyrics.cache[track.id]);
        return;
    }

    $("#playerLyrics").removeClass("none").attr("title", "Lyrics");
    showMessage("Looking for lyrics…");

    $.getJSON("/api/v1/music/" + encodeURIComponent(track.id) + "/lyrics").done(function(data) {
        lyrics.cache[track.id] = data;
        if(lyrics.trackId === track.id){
            render(track, data);
        }
    }).fail(function() {
        if(lyrics.trackId === track.id){
            showMessage("Couldn't load the lyrics right now.");
        }
    });
}

// which line we're on, and keep it in the middle of the panel
function tick(){
    if(!lyrics.lines.length || !panelOpen()){
        return;
    }

    let now = window.watrMusic.audio.currentTime + 0.15; // a hair early reads better than late
    let index = -1;
    for(let i = 0; i < lyrics.lines.length && lyrics.lines[i].time <= now; i++){
        index = i;
    }

    // what's lit: a line runs until its end time when it has one (Lyricsfile), otherwise until the next line.
    // lines can overlap (two singers), so more than one can be lit
    let lit = [];
    for(let i = 0; i <= index; i++){
        let line = lyrics.lines[i];
        let until = line.end !== undefined && line.end !== null ? line.end : (lyrics.lines[i + 1] ? lyrics.lines[i + 1].time : Infinity);
        if(now < until || (i === index && line.end === undefined)){
            lit.push(i);
        }
    }

    lit.forEach(i => lightWords(lyrics.lines[i], now));

    let key = index + ":" + lit.join(",");
    if(key === lyrics.activeKey){
        return;
    }
    lyrics.activeKey = key;

    lyrics.lines.forEach(function(line, i) {
        let on = lit.indexOf(i) !== -1;
        line.el.classList.toggle("active", on);
        line.el.classList.toggle("past", !on && i <= index);
        if(!on && line.words){
            // finished lines show every word as sung, ones not reached yet show none
            line.words.forEach(word => {
                word.el.classList.toggle("sung", i <= index);
                word.el.classList.remove("now");
            });
        }
    });
    lyrics.active = index;

    // leave the panel alone for a few seconds after someone scrolls it themselves
    if(index >= 0 && Date.now() - lyrics.userScrolled > 3000){
        let body = $("#lyricsBody")[0];
        let line = lyrics.lines[index].el;
        let reduce = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
        body.scrollTo({ top: line.offsetTop - body.clientHeight / 2 + line.offsetHeight / 2, behavior: reduce ? "auto" : "smooth" });
    }
}

// inside a lit line: words already sung, and the one being sung now
function lightWords(line, now){
    if(!line.words || !line.words.length){
        return;
    }
    line.words.forEach(function(word, i) {
        let next = line.words[i + 1];
        let until = word.end !== null ? word.end : (next ? next.time : (line.end !== null && line.end !== undefined ? line.end : Infinity));
        let started = word.time <= now;
        word.el.classList.toggle("now", started && now < until);
        word.el.classList.toggle("sung", started);
    });
}

// smoother than timeupdate (which only fires ~4 times a second), and only runs while it's needed
function loop(){
    cancelAnimationFrame(lyrics.frame);
    if(panelOpen() && lyrics.lines.length && window.watrMusic.isPlaying()){
        lyrics.frame = requestAnimationFrame(function step() {
            tick();
            lyrics.frame = requestAnimationFrame(step);
        });
    }
}

$("#playerLyrics").on("click", function() {
    setPanel(!panelOpen(), true);
});

$("#lyricsClose").on("click", function() {
    setPanel(false, true);
    $("#playerLyrics").trigger("focus");
});

$("#lyricsPanel").on("keydown", function(event) {
    if(event.key === "Escape"){
        $("#lyricsClose").trigger("click");
    }
});

$("#lyricsBody").on("click", ".lyricsLine", function() {
    let time = $(this).data("time");
    if(typeof time === "number"){
        lyrics.userScrolled = 0;
        window.watrMusic.seek(time);
        if(!window.watrMusic.isPlaying()){
            window.watrMusic.toggle();
        }
    }
}).on("wheel touchmove", function() {
    lyrics.userScrolled = Date.now();
});

$(window.watrMusic.audio).on("seeked", function() {
    lyrics.userScrolled = 0;
    lyrics.activeKey = "";
    tick();
});

$(document).on("music:change", function(_, track) {
    if(!track || track.id !== lyrics.trackId){
        load(track);
    }
});

$(document).on("music:state", loop);

// music.js may have restored a track before this file ran
load(window.watrMusic.current());
