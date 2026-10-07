// runs again on every visit (pages load in place), so everything stays inside this function
(function(){

// track list on /music: clicking a row plays from there, the rest of the list becomes the queue

function pageTracks(){
    return $("#trackList .track").map(function() {
        return $(this).data("track");
    }).get();
}

function markCurrent(){
    let track = window.watrMusic.current();
    let playing = window.watrMusic.isPlaying();

    $("#trackList .track").each(function() {
        let isCurrent = track && $(this).data("track").id === track.id;
        $(this).toggleClass("current", !!isCurrent).toggleClass("playing", !!isCurrent && playing);
        $(this).attr("aria-current", isCurrent ? "true" : null);
    });
}

$("#trackList").on("click", ".track", function() {
    let track = $(this).data("track");
    let current = window.watrMusic.current();

    // clicking the song that's already loaded just pauses/resumes it
    if(current && current.id === track.id){
        window.watrMusic.toggle();
        return;
    }

    window.watrMusic.playQueue(pageTracks(), $("#trackList .track").index(this));
});

$("#playAll").on("click", function() {
    window.watrMusic.playQueue(pageTracks(), 0);
});

$("#shuffleAll").on("click", function() {
    let tracks = pageTracks();
    for(let i = tracks.length - 1; i > 0; i--){
        let j = Math.floor(Math.random() * (i + 1));
        [tracks[i], tracks[j]] = [tracks[j], tracks[i]];
    }
    window.watrMusic.playQueue(tracks, 0);
});

// namespaced so coming back to this page replaces the listener instead of adding another
$(document).off(".musicpage").on("music:change.musicpage music:state.musicpage", markCurrent);
markCurrent();

})();
