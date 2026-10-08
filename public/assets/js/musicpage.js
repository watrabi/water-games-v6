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

// ---------- the ··· menu on each song: play next, add to queue, add to a playlist ----------

function closeTrackMenu(){
    $(".trackMenu").remove();
    $(".trackMore[aria-expanded]").attr("aria-expanded", "false");
}

function trackMenuItem(label, icon, onClick){
    return $("<button>", { type: "button", role: "menuitem" }).append($("<i>", { class: "ph-bold " + icon }), document.createTextNode(label)).on("click", function(event) {
        event.stopPropagation();
        onClick.call(this);
    });
}

$("#trackList").on("click", ".trackMore", function(event) {
    event.stopPropagation();
    let button = $(this);
    let open = button.attr("aria-expanded") === "true";
    closeTrackMenu();
    if(open){
        return;
    }

    let track = button.siblings(".track").data("track");
    let menu = $("<div>", { class: "trackMenu", role: "menu" });

    menu.append(
        trackMenuItem("Play next", "ph-arrow-bend-down-right", function() { window.watrMusic.playNext(track); closeTrackMenu(); }),
        trackMenuItem("Add to queue", "ph-queue", function() { window.watrMusic.addToQueue(track); closeTrackMenu(); })
    );

    if($("#trackList").is("[data-signed-in]")){
        let lists = $("<div>", { class: "trackMenuLists" }).append($("<p>", { class: "trackMenuHeading", text: "Add to playlist" }));
        menu.append(lists);

        $.getJSON("/api/v1/playlists").done(function(data) {
            data.playlists.forEach(function(list) {
                lists.append(trackMenuItem(list.name, "ph-playlist", function() {
                    let item = $(this).prop("disabled", true);
                    $.post("/api/v1/playlists/" + list.id + "/add", { track: track.id }).done(function() {
                        item.html('<i class="ph-bold ph-check"></i>Added to ' + $("<span>").text(list.name).html());
                    }).fail(function(xhr) {
                        item.text(apiMessage(xhr));
                    });
                }));
            });
            lists.append(trackMenuItem("New playlist…", "ph-plus", function() {
                let name = prompt("Name for the new playlist");
                if(name && name.trim()){
                    $.post("/api/v1/playlists", { name: name.trim(), track: track.id }).done(closeTrackMenu).fail(xhr => alert(apiMessage(xhr)));
                }
            }));
        });
    }

    button.attr("aria-expanded", "true").closest("li").append(menu);
    menu.find("button").first().trigger("focus");
});

$(document).off("click.trackmenu keydown.trackmenu").on("click.trackmenu", function(event) {
    if(!$(event.target).closest(".trackMenu").length){
        closeTrackMenu();
    }
}).on("keydown.trackmenu", function(event) {
    if(event.key === "Escape"){
        closeTrackMenu();
    }
});

// namespaced so coming back to this page replaces the listener instead of adding another
$(document).off(".musicpage").on("music:change.musicpage music:state.musicpage", markCurrent);
markCurrent();

})();
