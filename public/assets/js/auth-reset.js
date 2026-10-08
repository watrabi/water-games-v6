// forgot password + reset password pages. runs again on every visit (pages load in place)
(function(){

$("#forgotForm").on("submit", function(event) {
    event.preventDefault();

    let message = $("#forgotMsg").prop("hidden", true);
    let button = $("#forgotButton").prop("disabled", true);
    let data = { who: $("#forgotWho").val() };
    let captcha = $(this).find("[name=cf-turnstile-response]").val();
    if(captcha){
        data["cf-turnstile-response"] = captcha;
    }

    $.post("/api/v1/auth/forgot", data).done(function(result) {
        showNotice(message, result.message, "success");
    }).fail(function(xhr) {
        showNotice(message, apiMessage(xhr));
        button.prop("disabled", false);
        if(window.turnstile){
            turnstile.reset();
        }
    });
});

$("#resetForm").on("submit", function(event) {
    event.preventDefault();

    let message = $("#resetMsg").prop("hidden", true);
    let password = $("#resetPassword").val();

    if(password !== $("#resetPasswordConf").val()){
        showNotice(message, "Those passwords don't match.");
        return;
    }

    let button = $("#resetButton").prop("disabled", true);
    $.post("/api/v1/auth/reset", { token: $("#resetToken").val(), password: password }).done(function(result) {
        if(result.signedIn){
            window.location.href = "/home";
        } else {
            showNotice(message, "Password changed. Sign in with it now (you'll need your 2FA code too).", "success");
            setTimeout(() => window.location.href = "/auth/sign-in", 2500);
        }
    }).fail(function(xhr) {
        showNotice(message, apiMessage(xhr));
        button.prop("disabled", false);
    });
});

})();
