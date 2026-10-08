// runs again on every visit (pages load in place), so everything stays inside this function
(function(){

const MAX_AUDIO = 40 * 1024 * 1024;
const TAGS_SRC = "https://cdnjs.cloudflare.com/ajax/libs/jsmediatags/3.9.5/jsmediatags.min.js";
const TAGS_SRI = "sha384-JpTt7qxVx1X/pHeYiCfqFdKRu2HF1MBGr1kEXtbNIGwwryGWMbbW78onU3bdkAHZ";

let form = $("#uploadForm");
let msg = $("#uploadMsg");
let audioInput = $("#uploadAudio")[0];
let coverInput = $("#uploadCover")[0];
let title = $("#uploadTitle");
let artist = $("#uploadArtist");
let coverUrl = null;

// fields we filled in ourselves get replaced when you pick another file, ones you typed in don't
title.add(artist).on("input", function() {
    $(this).removeData("auto");
});

function autofill(input, value){
    value = (value || "").trim();
    if(value && (input.val() === "" || input.data("auto"))){
        input.val(value.slice(0, 255)).data("auto", true);
    }
}

// the tag reader is ~30KB, only fetched once someone actually picks a file
let tagsLoading = null;
function loadTagReader(){
    if(window.jsmediatags){
        return Promise.resolve(window.jsmediatags);
    }
    if(!tagsLoading){
        tagsLoading = new Promise(function(resolve, reject) {
            let script = document.createElement("script");
            script.src = TAGS_SRC;
            script.integrity = TAGS_SRI;
            script.crossOrigin = "anonymous";
            script.onload = () => resolve(window.jsmediatags);
            script.onerror = reject;
            document.head.append(script);
        });
    }
    return tagsLoading;
}

function readTags(file){
    return loadTagReader().then(function(reader) {
        return new Promise(function(resolve) {
            reader.read(file, { onSuccess: tag => resolve(tag.tags || {}), onError: () => resolve({}) });
        });
    }).catch(() => ({}));
}

function readDuration(file){
    return new Promise(function(resolve) {
        let url = URL.createObjectURL(file);
        let probe = new Audio();
        probe.preload = "metadata";
        probe.onloadedmetadata = function() {
            resolve(isFinite(probe.duration) ? Math.round(probe.duration) : "");
            URL.revokeObjectURL(url);
        };
        probe.onerror = function() {
            resolve("");
            URL.revokeObjectURL(url);
        };
        probe.src = url;
    });
}

function showCover(file){
    if(coverUrl){
        URL.revokeObjectURL(coverUrl);
        coverUrl = null;
    }

    let preview = $("#coverPreview").empty();
    if(file){
        coverUrl = URL.createObjectURL(file);
        $("<img>", { src: coverUrl, alt: "" }).appendTo(preview);
    } else {
        preview.append('<i class="ph-bold ph-music-notes" aria-hidden="true"></i>');
    }
    $("#coverRemove").prop("hidden", !file);
}

function setCoverFile(file){
    let transfer = new DataTransfer();
    if(file){
        transfer.items.add(file);
    }
    coverInput.files = transfer.files;
    showCover(file);
}

// "Artist - Song.mp3" -> artist and title
function guessFromName(name){
    name = name.replace(/\.[a-z0-9]{2,5}$/i, "").replace(/_/g, " ").trim();
    let parts = name.split(/\s+[-–—]\s+/);
    return parts.length === 2 ? { artist: parts[0], title: parts[1] } : { title: name };
}

async function audioPicked(){
    let file = audioInput.files[0];
    msg.prop("hidden", true);

    if(!file){
        $("#dropText").html("Drop a song here or <u>pick one</u>");
        $("#dropZone").removeClass("chosen");
        return;
    }

    $("#dropText").text(file.name);
    $("#dropZone").addClass("chosen");

    if(file.size > MAX_AUDIO){
        showNotice(msg, "That file is over 40MB, try a smaller one.");
    }

    let [tags, duration] = await Promise.all([readTags(file), readDuration(file)]);

    // they may have picked something else while we were reading this one
    if(audioInput.files[0] !== file){
        return;
    }

    $("#uploadDuration").val(duration);

    let guess = guessFromName(file.name);
    autofill(title, tags.title || guess.title);
    autofill(artist, tags.artist || guess.artist);

    let picture = tags.picture;
    let coverIsAuto = !coverInput.files.length || $(coverInput).data("auto");
    if(coverIsAuto){
        if(picture && picture.data && picture.data.length){
            let type = /png/i.test(picture.format) ? "image/png" : (/webp/i.test(picture.format) ? "image/webp" : "image/jpeg");
            let ext = type.split("/")[1].replace("jpeg", "jpg");
            setCoverFile(new File([new Uint8Array(picture.data)], "cover." + ext, { type: type }));
            $(coverInput).data("auto", true);
        } else if($(coverInput).data("auto")){
            setCoverFile(null);
        }
    }
}

$(audioInput).on("change", audioPicked);

$(coverInput).on("change", function() {
    $(coverInput).removeData("auto");
    showCover(coverInput.files[0] || null);
});

$("#coverRemove").on("click", function() {
    $(coverInput).removeData("auto");
    setCoverFile(null);
});

$("#dropZone").on("dragenter dragover", function() {
    $(this).addClass("over");
}).on("dragleave drop", function() {
    $(this).removeClass("over");
});

form.on("submit", function(event) {
    event.preventDefault();

    let file = audioInput.files[0];
    if(!file){
        showNotice(msg, "Pick an audio file first.");
        return;
    }
    if(file.size > MAX_AUDIO){
        showNotice(msg, "That file is over 40MB, try a smaller one.");
        return;
    }

    let button = $("#uploadButton").prop("disabled", true);
    let progress = $("#uploadProgress").prop("hidden", false);
    let bar = progress.find("span").css("width", "0%");
    msg.prop("hidden", true);

    $.ajax({
        url: "/api/v1/music/upload",
        method: "POST",
        data: new FormData(form[0]),
        processData: false,
        contentType: false,
        xhr: function() {
            let xhr = new XMLHttpRequest();
            xhr.upload.addEventListener("progress", function(e) {
                if(e.lengthComputable){
                    // the last bit is the server saving it and looking for lyrics
                    bar.css("width", Math.round(e.loaded / e.total * 92) + "%");
                }
            });
            return xhr;
        }
    }).done(function(data) {
        bar.css("width", "100%");
        let found = {
            synced: "Uploaded! Found lyrics that follow along with the song.",
            plain: "Uploaded! Found the lyrics (not timed, so they won't scroll by themselves).",
            none: "Uploaded! Couldn't find lyrics for it, adding the artist can help."
        };
        try { sessionStorage.setItem("watrUploadMsg", found[data.lyrics] || found.none); } catch (e) {}
        window.watrNav ? window.watrNav.reload() : location.reload();
    }).fail(function(xhr) {
        showNotice(msg, apiMessage(xhr));
        progress.prop("hidden", true);
        button.prop("disabled", false);
    });
});

// message from the upload that just reloaded this page
try {
    let done = sessionStorage.getItem("watrUploadMsg");
    if(done){
        sessionStorage.removeItem("watrUploadMsg");
        showNotice(msg, done, "success");
    }
} catch (e) {}

// your uploads
function listTrack(row){
    return $(row).closest("li").data("track");
}

$("#uploadList").on("click", "[data-play]", function() {
    let tracks = $("#uploadList li").map(function() { return $(this).data("track"); }).get();
    window.watrMusic.playQueue(tracks, $("#uploadList li").index($(this).closest("li")));
}).on("click", "[data-delete]", function() {
    let button = $(this);

    // second click confirms, no browser dialog
    if(!button.hasClass("really")){
        button.addClass("really").text("Really delete?");
        setTimeout(() => button.removeClass("really").text("Delete"), 4000);
        return;
    }

    button.prop("disabled", true);
    $.post("/api/v1/music/" + listTrack(this).id + "/delete").done(function() {
        let row = button.closest("li");
        row.remove();
        if(!$("#uploadList li").length){
            window.watrNav ? window.watrNav.reload() : location.reload();
        }
    }).fail(function(xhr) {
        button.prop("disabled", false);
        showNotice(msg, apiMessage(xhr));
    });
});

})();
