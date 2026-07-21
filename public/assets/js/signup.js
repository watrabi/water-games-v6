let usernameInput = $("#username");
let emailInput = $("#email")
let passwordInput = $("#password");
let passwordValInput = $("#passwordconf");
let submitBtn = $("#signupButton");
let errorMsg = $("#dangermsg");

function register() {

    errorMsg.css("display", "none");

    password = passwordInput.val();
    passwordConf = passwordValInput.val();

    if(password != passwordConf){
        errorMsg.text("Your passwords do not match.");
        errorMsg.css("display", "block");
        return;
    }

    $.post("/api/v1/auth/register", {
        username: usernameInput.val(),
        password: passwordInput.val()
    }).fail(onError).done(didHappen);
}

function didHappen(data){
    if(data.status == "okay"){
        errorMsg.css("display", "none");
        window.location.href = "/home";
    } else {
        errorMsg.text(data.message);
        errorMsg.css("display", "block");
    }
}

function onError() {
    errorMsg.text("An error occured.");
    errorMsg.css("display", "block");
}

$("#sidebar").toggleClass("closed");