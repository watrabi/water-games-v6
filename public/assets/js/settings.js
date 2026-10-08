// runs again on every visit (pages load in place), so everything stays inside this function
(function(){

// ---------- tabs (vertical on the left, a row on phones) ----------

let tabs = $(".settingsTab");

function openTab(name, focus){
    let tab = tabs.filter('[data-tab="' + name + '"]');
    if(!tab.length){
        tab = tabs.first();
    }

    tabs.attr({ "aria-selected": "false", tabindex: "-1" });
    tab.attr({ "aria-selected": "true", tabindex: null });
    $(".settingsPanels > [role=tabpanel]").prop("hidden", true);
    $("#" + tab.attr("aria-controls")).prop("hidden", false);

    if(focus){
        tab.trigger("focus");
    }
    return tab.data("tab");
}

tabs.on("click", function() {
    let name = openTab($(this).data("tab"));
    // replaceState so switching tabs doesn't fill up the back button
    history.replaceState(history.state, "", "#" + name);
});

// arrow keys move between tabs like any other tab list
tabs.on("keydown", function(event) {
    let index = tabs.index(this);
    let next = { ArrowDown: index + 1, ArrowRight: index + 1, ArrowUp: index - 1, ArrowLeft: index - 1, Home: 0, End: tabs.length - 1 }[event.key];
    if(next === undefined){
        return;
    }
    event.preventDefault();
    let tab = tabs.eq((next + tabs.length) % tabs.length);
    tab.trigger("click").trigger("focus");
});

openTab(location.hash.slice(1));

// ---------- profile picture ----------

let avatarMsg = $("#avatarMsg");

// swap the new picture in here and on the top bar without a reload
function showAvatar(url){
    $("#avatarPreview .avatar, #userMenu .avatar").each(function() {
        let box = $(this).empty();
        if(url){
            $("<img>", { src: url, alt: "" }).appendTo(box);
        } else {
            box.text($("#userMenu .navUsername").text().charAt(0).toUpperCase());
        }
    });
    $("#avatarRemove").prop("hidden", !url);
    $("label[for=avatarInput]").html('<i class="ph-bold ph-image"></i>' + (url ? "Change picture" : "Upload picture"));
}

$("#avatarInput").on("change", function() {
    let file = this.files[0];
    let input = this;
    if(!file){
        return;
    }

    if(file.size > 5 * 1024 * 1024){
        showNotice(avatarMsg, "Pictures have to be under 5MB.");
        input.value = "";
        return;
    }

    let data = new FormData();
    data.append("avatar", file);
    showNotice(avatarMsg, "Uploading…", "success");

    $.ajax({ url: "/api/v1/account/avatar", method: "POST", data: data, processData: false, contentType: false }).done(function(response) {
        showAvatar(response.avatar);
        showNotice(avatarMsg, response.message, "success");
    }).fail(function(xhr) {
        showNotice(avatarMsg, apiMessage(xhr));
    }).always(function() {
        input.value = "";
    });
});

$("#avatarRemove").on("click", function() {
    let button = $(this).prop("disabled", true);

    $.post("/api/v1/account/avatar/remove").done(function(response) {
        showAvatar(null);
        showNotice(avatarMsg, response.message, "success");
    }).fail(function(xhr) {
        showNotice(avatarMsg, apiMessage(xhr));
    }).always(function() {
        button.prop("disabled", false);
    });
});

// ---------- forms ----------

let blurbInput = $("#blurb");
let blurbMsg = $("#blurbMsg");
let passwordMsg = $("#passwordMsg");

blurbInput.on("input", function() {
    $("#blurbCount").text(blurbInput.val().length);
});

$("#blurbForm").on("submit", function(event) {
    event.preventDefault();
    let button = $(this).find("button").prop("disabled", true);

    $.post("/api/v1/account/blurb", { blurb: blurbInput.val() }).done(function(data) {
        showNotice(blurbMsg, data.message, "success");
    }).fail(function(xhr) {
        showNotice(blurbMsg, apiMessage(xhr));
    }).always(function() {
        button.prop("disabled", false);
    });
});

$("#passwordForm").on("submit", function(event) {
    event.preventDefault();
    let form = this;
    let newPassword = $("#newPassword").val();

    if(newPassword.length < 8){
        showNotice(passwordMsg, "Passwords need to be at least 8 characters.");
        return;
    }

    if(newPassword != $("#newPasswordConf").val()){
        showNotice(passwordMsg, "The new passwords don't match.");
        return;
    }

    let button = $(form).find("button").prop("disabled", true);

    $.post("/api/v1/account/password", {
        current: $("#currentPassword").val(),
        new: newPassword
    }).done(function(data) {
        showNotice(passwordMsg, data.message, "success");
        form.reset();
    }).fail(function(xhr) {
        showNotice(passwordMsg, apiMessage(xhr));
    }).always(function() {
        button.prop("disabled", false);
    });
});

// deleting one of your own themes, second click confirms
$("#themeForm").on("click", "[data-delete-theme]", function(event) {
    event.preventDefault();
    let button = $(this);

    if(!button.hasClass("really")){
        button.addClass("really").attr("title", "Click again to delete");
        setTimeout(() => button.removeClass("really").attr("title", "Delete"), 4000);
        return;
    }

    button.prop("disabled", true);
    $.post("/api/v1/themes/custom/" + button.data("delete-theme") + "/delete").done(function() {
        location.reload();
    }).fail(function(xhr) {
        button.prop("disabled", false);
        showNotice($("#themeMsg"), apiMessage(xhr));
    });
});

$("#themeForm").on("submit", function(event) {
    event.preventDefault();

    $.post("/api/v1/theme", {
        theme: $(this).find("input[name=theme]:checked").val() || "auto",
        effects: $("#effectsToggle").is(":checked") ? "on" : "off"
    }).done(function() {
        location.reload();
    }).fail(function(xhr) {
        showNotice($("#themeMsg"), apiMessage(xhr));
    });
});

})();
