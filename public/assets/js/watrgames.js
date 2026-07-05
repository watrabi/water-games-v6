$("#branding").on("click", function(event) {
    event.preventDefault();
    $("#sidebar").toggleClass("closed");
});

$(document).ready(function(){
    twemoji.parse(document.body);
});
