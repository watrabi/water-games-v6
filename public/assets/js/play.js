// runs again on every visit (pages load in place), so everything stays inside this function
(function(){

let favoriteButton = $("#favoriteButton");
let playError = $("#playError");

$("#fullscreenButton").on("click", function() {
    let player = document.getElementById("player");

    if(player.requestFullscreen){
        player.requestFullscreen();
    } else if(player.webkitRequestFullscreen){
        player.webkitRequestFullscreen();
    }
});

function setFavorited(favorited){
    favoriteButton.toggleClass("on", favorited).attr("aria-pressed", favorited);
    favoriteButton.find("i").attr("class", (favorited ? "ph-fill" : "ph-bold") + " ph-heart");
    favoriteButton.find("span").text(favorited ? "Favorited" : "Favorite");
}

favoriteButton.on("click", function() {
    favoriteButton.prop("disabled", true);
    playError.prop("hidden", true);

    $.post("/api/v1/games/favorite", { id: favoriteButton.data("id") }).done(function(data) {
        setFavorited(data.favorited);
        $("#favoriteCount").text(Number(data.favorites).toLocaleString());
        $("#favoriteLabel").text(data.favorites == 1 ? "favorite" : "favorites");
    }).fail(function(xhr) {
        showNotice(playError, apiMessage(xhr));
    }).always(function() {
        favoriteButton.prop("disabled", false);
    });
});

})();
