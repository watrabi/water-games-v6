// global music player. state lives in localStorage so the next page can pick
// up the same track at the same spot (browsers may block autoplay after a
// page change, in that case it just waits paused for you to hit play)

const musicStorageKey = "watrMusic";

let music = {
    audio: new Audio(),
    queue: [],
    index: 0,
    volume: 0.8,
    muted: false,
    seeking: false,
    pendingTime: null, // where to jump once the restored track has loaded
    lastSave: 0
};

music.audio.preload = "metadata";

function formatTime(seconds){
    if(!isFinite(seconds) || seconds < 0){
        return "0:00";
    }
    seconds = Math.floor(seconds);
    return Math.floor(seconds / 60) + ":" + String(seconds % 60).padStart(2, "0");
}

function setSeekFill(fraction){
    $("#playerSeek")[0].style.setProperty("--progress", (fraction * 100) + "%");
}

function saveMusic(){
    try {
        localStorage.setItem(musicStorageKey, JSON.stringify({
            queue: music.queue,
            index: music.index,
            time: music.pendingTime !== null ? music.pendingTime : (music.audio.currentTime || 0),
            playing: !music.audio.paused,
            volume: music.volume,
            muted: music.muted
        }));
    } catch (e) {}
}

function loadSavedMusic(){
    try {
        return JSON.parse(localStorage.getItem(musicStorageKey));
    } catch (e) {
        return null;
    }
}

function currentTrack(){
    return music.queue[music.index] || null;
}

function updateMediaSession(track){
    if(!("mediaSession" in navigator) || !track){
        return;
    }

    navigator.mediaSession.metadata = new MediaMetadata({
        title: track.title,
        artist: track.artist || "",
        artwork: track.cover ? [{ src: track.cover }] : []
    });
}

function renderTrack(){
    let track = currentTrack();
    if(!track){
        return;
    }

    $("#playerTitle").text(track.title);
    $("#playerArtist").text(track.artist || "Unknown artist");

    let cover = $("#playerCover").empty();
    if(track.cover){
        $("<img>", { src: track.cover, alt: "" }).appendTo(cover);
    } else {
        cover.append('<i class="ph-bold ph-music-notes"></i>');
    }

    $("#playerNext").prop("disabled", music.index >= music.queue.length - 1);
    $("#musicPlayer").prop("hidden", false);
    $("body").addClass("has-player");

    updateMediaSession(track);
    $(document).trigger("music:change", [track]);
}

function renderPlayState(){
    let playing = !music.audio.paused;
    $("#playerToggle")
        .attr("aria-label", playing ? "Pause" : "Play")
        .find("i").attr("class", "ph-bold " + (playing ? "ph-pause" : "ph-play"));
    $(document).trigger("music:state", [playing]);
}

function renderVolume(){
    music.audio.volume = music.volume;
    music.audio.muted = music.muted;
    $("#playerVolume").val(Math.round(music.volume * 100));

    let icon = music.muted || music.volume === 0 ? "ph-speaker-x" : (music.volume < 0.5 ? "ph-speaker-low" : "ph-speaker-high");
    $("#playerMute").attr("aria-label", music.muted ? "Unmute" : "Mute").find("i").attr("class", "ph-bold " + icon);
}

// loads the track at index. countPlay is false when restoring after a page change
function loadTrack(index, autoplay, countPlay, startAt){
    music.index = index;
    let track = currentTrack();
    if(!track){
        return;
    }

    music.pendingTime = startAt || null;
    music.audio.src = track.src;
    renderTrack();

    if(startAt){
        $(music.audio).one("loadedmetadata", function() {
            music.audio.currentTime = Math.min(startAt, music.audio.duration || startAt);
            music.pendingTime = null;
        });
    }

    if(autoplay){
        music.audio.play().catch(function() {
            renderPlayState();
        });
    }

    if(countPlay){
        $.post("/api/v1/music/play", { id: track.id });
    }

    saveMusic();
}

// public bits, used by the music page
window.watrMusic = {
    playQueue: function(tracks, index){
        music.queue = tracks;
        loadTrack(index || 0, true, true);
    },
    toggle: function(){
        if(!currentTrack()){
            return;
        }
        if(music.audio.paused){
            music.audio.play().catch(function() {});
        } else {
            music.audio.pause();
        }
    },
    current: currentTrack,
    audio: music.audio,
    seek: function(seconds){
        if(currentTrack()){
            music.audio.currentTime = Math.max(0, seconds);
        }
    },
    isPlaying: function(){
        return !music.audio.paused;
    }
};

function nextTrack(){
    if(music.index < music.queue.length - 1){
        loadTrack(music.index + 1, true, true);
    }
}

function previousTrack(){
    // like every other player: restart the song unless you're right at the start
    if(music.audio.currentTime > 3 || music.index === 0){
        music.audio.currentTime = 0;
        return;
    }
    loadTrack(music.index - 1, true, true);
}

$(music.audio).on("play pause", function() {
    renderPlayState();
    saveMusic();
});

$(music.audio).on("timeupdate", function() {
    let duration = music.audio.duration;

    if(!music.seeking){
        let fraction = duration ? music.audio.currentTime / duration : 0;
        $("#playerCurrent").text(formatTime(music.audio.currentTime));
        $("#playerSeek").val(Math.round(fraction * 1000));
        setSeekFill(fraction);
    }

    if(Date.now() - music.lastSave > 2000){
        music.lastSave = Date.now();
        saveMusic();
    }
});

$(music.audio).on("loadedmetadata durationchange", function() {
    $("#playerDuration").text(formatTime(music.audio.duration));
});

$(music.audio).on("ended", function() {
    if(music.index < music.queue.length - 1){
        nextTrack();
    } else {
        music.audio.currentTime = 0;
        renderPlayState();
        saveMusic();
    }
});

$(music.audio).on("error", function() {
    $("#playerArtist").text("Couldn't load this track");
});

$("#playerToggle").on("click", window.watrMusic.toggle);
$("#playerNext").on("click", nextTrack);
$("#playerPrev").on("click", previousTrack);

$("#playerSeek").on("input", function() {
    music.seeking = true;
    setSeekFill($(this).val() / 1000);
    $("#playerCurrent").text(formatTime($(this).val() / 1000 * (music.audio.duration || 0)));
}).on("change", function() {
    if(music.audio.duration){
        music.audio.currentTime = $(this).val() / 1000 * music.audio.duration;
    }
    music.seeking = false;
});

$("#playerVolume").on("input", function() {
    music.volume = $(this).val() / 100;
    music.muted = false;
    renderVolume();
    saveMusic();
});

$("#playerMute").on("click", function() {
    music.muted = !music.muted;
    renderVolume();
    saveMusic();
});

$("#playerClose").on("click", function() {
    music.audio.pause();
    music.audio.removeAttribute("src");
    music.queue = [];
    $("#musicPlayer").prop("hidden", true);
    $("body").removeClass("has-player");
    try { localStorage.removeItem(musicStorageKey); } catch (e) {}
    $(document).trigger("music:change", [null]);
});

if("mediaSession" in navigator){
    navigator.mediaSession.setActionHandler("play", function() { music.audio.play(); });
    navigator.mediaSession.setActionHandler("pause", function() { music.audio.pause(); });
    navigator.mediaSession.setActionHandler("nexttrack", nextTrack);
    navigator.mediaSession.setActionHandler("previoustrack", previousTrack);
}

window.addEventListener("pagehide", saveMusic);

// pick up where the last page left off
let savedMusic = loadSavedMusic();
if(savedMusic && Array.isArray(savedMusic.queue) && savedMusic.queue.length){
    music.queue = savedMusic.queue;
    music.volume = typeof savedMusic.volume === "number" ? savedMusic.volume : 0.8;
    music.muted = !!savedMusic.muted;
    renderVolume();
    loadTrack(Math.min(savedMusic.index || 0, music.queue.length - 1), !!savedMusic.playing, false, savedMusic.time || 0);
} else {
    renderVolume();
}
