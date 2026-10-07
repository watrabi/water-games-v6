// runs again on every visit (pages load in place), so everything stays inside this function
(function(){

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
