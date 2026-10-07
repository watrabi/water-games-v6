// runs again on every visit (pages load in place), so everything stays inside this function
(function(){

let usernameInput = $("#username");
let passwordInput = $("#password");
let submitBtn = $("#loginButton");
let errorMsg = $("#dangermsg");

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
        password: passwordInput.val()
    }).done(function(data) {
        if(data.status == "okay"){
            window.location.href = "/home";
        } else {
            showNotice(errorMsg, data.message);
            submitBtn.prop("disabled", false).text("Sign in");
        }
    }).fail(function(xhr) {
        showNotice(errorMsg, apiMessage(xhr));
        submitBtn.prop("disabled", false).text("Sign in");
    });
});

})();
