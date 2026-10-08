// Water Games SDK, for games that want a leaderboard.
//
//   <script src="/assets/js/watr-sdk.js"></script>
//   watr.submitScore(1234);                          // when a run ends
//   watr.submitScore(83456).then(r => r.personalBest)  // ms, for games whose board shows times
//
// the game page (play.js) does the sending, so this works the same for games hosted on the site and on other
// sites. outside the site (opening the game on its own) it quietly does nothing.
// the game's leaderboard has to be switched on in the admin panel first.
(function(){

    if(window.watr && window.watr.submitScore){
        return;
    }

    let inFrame = window.parent && window.parent !== window;
    let waiting = {};
    let nextId = 1;

    window.addEventListener("message", function(event) {
        let data = event.data;
        if(event.source !== window.parent || !data || data.source !== "watr" || !waiting[data.id]){
            return;
        }
        waiting[data.id](data.result || { ok: false });
        delete waiting[data.id];
    });

    // resolves with { ok, personalBest, best, rank, text, message } once the site has it, or { ok: false }
    function submitScore(score){
        return new Promise(function(resolve) {
            if(!inFrame || typeof score !== "number" || !isFinite(score)){
                return resolve({ ok: false, message: "not on Water Games" });
            }

            let id = nextId++;
            waiting[id] = resolve;
            // a score isn't a secret, and games on other sites don't know our address, so any origin is fine
            window.parent.postMessage({ source: "watr-sdk", type: "score", id: id, score: Math.round(score) }, "*");

            // the page might not be listening (an old version, or not signed in), don't leave the game hanging
            setTimeout(function() {
                if(waiting[id]){
                    waiting[id]({ ok: false, message: "no answer" });
                    delete waiting[id];
                }
            }, 10000);
        });
    }

    window.watr = { submitScore: submitScore, available: inFrame };
})();
