// runs again on every visit (pages load in place), so everything stays inside this function
(function(){

let usernameInput = $("#username");
let emailInput = $("#email");
let passwordInput = $("#password");
let passwordValInput = $("#passwordconf");
let submitBtn = $("#signupButton");
let errorMsg = $("#dangermsg");

function resetButton(){
    submitBtn.prop("disabled", false).text("Create account");

    // turnstile tokens are single use
    if(window.turnstile){
        turnstile.reset();
    }
}

$("#signupForm").on("submit", function(event) {
    event.preventDefault();
    errorMsg.prop("hidden", true);

    let username = usernameInput.val().trim();
    let password = passwordInput.val();

    if(!username || !password){
        showNotice(errorMsg, "Please make sure the username and password are filled out.");
        return;
    }

    if(password.length < 8){
        showNotice(errorMsg, "Passwords need to be at least 8 characters.");
        return;
    }

    if(password != passwordValInput.val()){
        showNotice(errorMsg, "Your passwords do not match.");
        return;
    }

    submitBtn.prop("disabled", true).text("Creating account...");

    $.post("/api/v1/auth/register", {
        username: username,
        email: emailInput.val().trim(),
        password: password,
        "cf-turnstile-response": $("[name='cf-turnstile-response']").val() || ""
    }).done(function(data) {
        if(data.status == "okay"){
            window.location.href = "/home";
        } else {
            showNotice(errorMsg, data.message);
            resetButton();
        }
    }).fail(function(xhr) {
        showNotice(errorMsg, apiMessage(xhr));
        resetButton();
    });
});

})();
