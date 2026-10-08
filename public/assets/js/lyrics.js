// lyrics for whatever the player has on. synced (LRC) lyrics light up line by line with the song,
// clicking a line jumps there. loaded once like music.js, the panel sits outside #main so it stays put
// across page changes

const lyricsPrefKey = "watrLyrics"; // "open" | "closed", unset means open it the first time we find some

let lyrics = {
    cache: {},         // track id -> {lyrics, synced} once the server answers
    trackId: null,
    lines: [],         // [{time, text, el}] for synced, [] for plain
    active: -1,
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
    body.toggleClass("synced", !!data.synced);

    if(data.synced){
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

    if(index === lyrics.active){
        return;
    }

    lyrics.lines.forEach(function(line, i) {
        line.el.classList.toggle("active", i === index);
        line.el.classList.toggle("past", i < index);
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
