// runs again on every visit (pages load in place), so everything stays inside this function
(function(){

let usernameInput = $("#username");
let passwordInput = $("#password");
let submitBtn = $("#loginButton");
let errorMsg = $("#dangermsg");
let challenge = null;
let captchaId = null;

// after a few wrong passwords the server wants a captcha too
function showCaptcha(){
    let box = document.getElementById("loginCaptcha");
    if(!box || !window.turnstile || !box.dataset.sitekey){
        return;
    }
    box.hidden = false;
    if(captchaId === null){
        captchaId = turnstile.render(box, { sitekey: box.dataset.sitekey, theme: "dark" });
    } else {
        turnstile.reset(captchaId);
    }
}

function captchaToken(){
    return captchaId !== null && window.turnstile ? (turnstile.getResponse(captchaId) || "") : "";
}

function failed(message, data){
    showNotice(errorMsg, message);
    submitBtn.prop("disabled", false).text("Sign in");
    if(data && data.captcha){
        showCaptcha();
    }
}

$("#loginForm").on("submit", function(event) {
    event.preventDefault();
    errorMsg.prop("hidden", true);

    if(!usernameInput.val().trim() || !passwordInput.val()){
        showNotice(errorMsg, "Please make sure both fields are filled out.");
        return;
    }

    submitBtn.prop("disabled", true).text("Signing in...");

    $.post("/api/v1/auth/login", {
        username: usernameInput.val(),
        password: passwordInput.val(),
        "cf-turnstile-response": captchaToken()
    }).done(function(data) {
        if(data.status == "okay"){
            window.location.href = "/home";
        } else if(data.status == "2fa"){
            // right password, now the code
            challenge = data.token;
            $("#loginForm").prop("hidden", true);
            $("#twoFactorForm").prop("hidden", false);
            $("#twoFactorCode").trigger("focus");
        } else {
            failed(data.message, data);
        }
    }).fail(function(xhr) {
        failed(apiMessage(xhr), xhr.responseJSON);
    });
});

$("#twoFactorForm").on("submit", function(event) {
    event.preventDefault();

    let message = $("#twoFactorMsg").prop("hidden", true);
    let button = $("#twoFactorButton").prop("disabled", true).text("Checking...");

    $.post("/api/v1/auth/2fa", { token: challenge, code: $("#twoFactorCode").val() }).done(function() {
        window.location.href = "/home";
    }).fail(function(xhr) {
        showNotice(message, apiMessage(xhr));
        button.prop("disabled", false).text("Verify");
        $("#twoFactorCode").val("").trigger("focus");
    });
});

})();
