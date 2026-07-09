let usernameInput = $("#username");
let passwordInput = $("#password");
let submitBtn = $("#loginButton");
let errorMsg = $("#dangermsg");

function login() {

    errorMsg.css("display", "none");

    password = passwordInput.val();

    $.post("/api/v1/auth/login", {
        username: usernameInput.val(),
        password: passwordInput.val()
    }).fail(onError).done(didHappen);
}

function didHappen(data){
    if(data.status == "Okay"){
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