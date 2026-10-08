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

// ---------- email ----------

$("#emailForm").on("submit", function(event) {
    event.preventDefault();
    let message = $("#emailMsg");

    $.post("/api/v1/account/email", { email: $("#emailInput").val(), password: $("#emailPassword").val() }).done(function(data) {
        showNotice(message, data.message, "success");
        $("#emailPassword").val("");
    }).fail(function(xhr) {
        showNotice(message, apiMessage(xhr));
    });
});

// ---------- two-factor ----------

function showRecoveryCodes(codes){
    let list = $("#recoveryList").empty();
    codes.forEach(code => $("<li>").append($("<code>", { text: code })).appendTo(list));
    $("#recoveryCodes").prop("hidden", false);
    $("#recoveryCopy").off("click").on("click", function() {
        navigator.clipboard.writeText(codes.join("\n")).then(() => $(this).text("Copied"));
    });
}

$("#twoFactorStart").on("click", function() {
    let button = $(this).prop("disabled", true);
    let message = $("#twoFactorMsg").prop("hidden", true);

    $.post("/api/v1/account/2fa/setup").done(function(data) {
        button.prop("hidden", true);
        $("#twoFactorSetup").prop("hidden", false);
        $("#twoFactorSecret").text(data.secret.replace(/(.{4})/g, "$1 ").trim());

        // the qr code is drawn here, the secret never goes to another site
        let box = $("#twoFactorQr").empty();
        if(window.qrcode){
            let qr = qrcode(0, "M");
            qr.addData(data.uri);
            qr.make();
            box.html(qr.createSvgTag({ cellSize: 4, margin: 2, scalable: true }));
            box.find("svg").attr({ role: "img", "aria-label": "QR code for your authenticator app" });
        } else {
            box.append($("<p>", { class: "hint", text: "The QR code didn't load. Use the key below." }));
        }
        $("#twoFactorCodeInput").trigger("focus");
    }).fail(function(xhr) {
        showNotice(message, apiMessage(xhr));
        button.prop("disabled", false);
    });
});

$("#twoFactorEnable").on("submit", function(event) {
    event.preventDefault();
    let message = $("#twoFactorMsg").prop("hidden", true);

    $.post("/api/v1/account/2fa/enable", { code: $("#twoFactorCodeInput").val() }).done(function(data) {
        $("#twoFactorSetup").prop("hidden", true);
        showNotice(message, "Two-factor sign in is on. Other devices were signed out.", "success");
        showRecoveryCodes(data.recoveryCodes);
    }).fail(function(xhr) {
        showNotice(message, apiMessage(xhr));
    });
});

$("#twoFactorManage").on("submit", function(event) {
    event.preventDefault();
    let action = event.originalEvent && event.originalEvent.submitter ? $(event.originalEvent.submitter).data("action") : "recovery";
    let message = $("#twoFactorMsg").prop("hidden", true);
    let data = { password: $("#manageTwoFactorPassword").val(), code: $("#manageTwoFactorCode").val() };

    $.post("/api/v1/account/2fa/" + action, data).done(function(result) {
        $("#manageTwoFactorPassword, #manageTwoFactorCode").val("");
        if(action === "disable"){
            showNotice(message, result.message, "success");
            setTimeout(() => window.watrNav ? window.watrNav.reload() : location.reload(), 1200);
        } else {
            showRecoveryCodes(result.recoveryCodes);
        }
    }).fail(function(xhr) {
        showNotice(message, apiMessage(xhr));
    });
});

// ---------- sessions ----------

$("[data-revoke]").on("click", function() {
    let button = $(this).prop("disabled", true);
    $.post("/api/v1/account/sessions/" + button.data("revoke") + "/revoke").done(function() {
        button.closest("li").remove();
    }).fail(function(xhr) {
        showNotice($("#sessionsMsg"), apiMessage(xhr));
        button.prop("disabled", false);
    });
});

$("#sessionsOthers").on("click", function() {
    $.post("/api/v1/account/sessions/others").done(function(data) {
        $(".sessionList [data-session]").filter(function() { return $(this).find("[data-revoke]").length; }).remove();
        showNotice($("#sessionsMsg"), data.message, "success");
        $("#sessionsOthers").remove();
    });
});

// ---------- privacy + saves ----------

$("#recapForm").on("submit", function(event) {
    event.preventDefault();
    $.post("/api/v1/account/recap", { email: $("#recapEmail").is(":checked") ? "1" : "" }).done(function(data) {
        showNotice($("#recapMsg"), data.message, "success");
    }).fail(function(xhr) {
        showNotice($("#recapMsg"), apiMessage(xhr));
    });
});

$("#privacyForm").on("submit", function(event) {
    event.preventDefault();
    $.post("/api/v1/account/privacy", { share_activity: $("#shareActivity").is(":checked") ? "1" : "" }).done(function(data) {
        showNotice($("#privacyMsg"), data.message, "success");
    }).fail(function(xhr) {
        showNotice($("#privacyMsg"), apiMessage(xhr));
    });
});

$("[data-delete-save]").on("click", function() {
    let button = $(this);
    if(!confirm("Delete your cloud save for " + button.data("name") + "? Progress on this device stays, but other devices won't get it.")){
        return;
    }
    $.post("/api/v1/play/" + button.data("delete-save") + "/save/delete").done(function() {
        try { localStorage.removeItem("watrCloud:" + button.data("delete-save")); } catch (e) {}
        button.closest("li").remove();
    }).fail(function(xhr) {
        showNotice($("#savesMsg"), apiMessage(xhr));
    });
});

// ---------- delete account ----------

$("#deleteForm").on("submit", function(event) {
    event.preventDefault();
    if(!confirm("Really delete your account? There's no undo.")){
        return;
    }

    let data = { confirm: $("#deleteConfirm").val(), password: $("#deletePassword").val(), code: $("#deleteCode").val() || "" };
    $.post("/api/v1/account/delete", data).done(function() {
        try { localStorage.clear(); sessionStorage.clear(); } catch (e) {}
        window.location.href = "/";
    }).fail(function(xhr) {
        showNotice($("#deleteMsg"), apiMessage(xhr));
    });
});

})();
