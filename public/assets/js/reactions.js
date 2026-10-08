// the row of emoji reactions under a comment or chat message, shared by comments.js and chat.js.
// loaded once in the page head, so it lives on window instead of inside a page script
(function(){

    function el(tag, className, text){
        let node = document.createElement(tag);
        if(className){
            node.className = className;
        }
        if(text !== undefined && text !== null){
            node.textContent = text;
        }
        return node;
    }

    function closePickers(except){
        document.querySelectorAll(".reactPicker:not([hidden])").forEach(function(picker) {
            if(picker !== except){
                picker.hidden = true;
                let add = picker.previousElementSibling;
                if(add){
                    add.setAttribute("aria-expanded", "false");
                }
            }
        });
    }

    document.addEventListener("click", function(event) {
        if(!event.target.closest(".reactAdd, .reactPicker")){
            closePickers();
        }
    });
    document.addEventListener("keydown", function(event) {
        if(event.key === "Escape"){
            closePickers();
        }
    });

    // reactions: [{emoji, count, users: [ids]}], me: your id (null = can't react), choices: the allowed emoji,
    // toggle(emoji) -> promise of the new list. returns the element; call .update(list) to redraw it
    function bar(reactions, me, choices, toggle){
        let wrap = el("div", "reactions");
        let current = reactions || [];

        function draw(){
            wrap.textContent = "";
            current.forEach(function(r) {
                let mine = me !== null && r.users.indexOf(me) !== -1;
                let chip = el("button", "reactChip" + (mine ? " mine" : ""));
                chip.type = "button";
                chip.disabled = me === null;
                chip.setAttribute("aria-pressed", mine ? "true" : "false");
                chip.setAttribute("aria-label", r.emoji + " " + r.count + (mine ? ", including you" : ""));
                chip.append(el("span", "reactEmoji", r.emoji), el("span", "reactCount", String(r.count)));
                chip.addEventListener("click", () => send(r.emoji));
                wrap.append(chip);
            });

            if(me !== null && choices && choices.length){
                let add = el("button", "reactAdd");
                add.type = "button";
                add.title = "React";
                add.setAttribute("aria-label", "Add a reaction");
                add.setAttribute("aria-haspopup", "true");
                add.innerHTML = '<i class="ph-bold ph-smiley"></i>';

                let picker = el("div", "reactPicker");
                picker.hidden = true;
                picker.setAttribute("role", "menu");
                choices.forEach(function(emoji) {
                    let option = el("button", "reactOption", emoji);
                    option.type = "button";
                    option.setAttribute("role", "menuitem");
                    option.addEventListener("click", function() {
                        picker.hidden = true;
                        send(emoji);
                    });
                    picker.append(option);
                });

                add.setAttribute("aria-expanded", "false");
                add.addEventListener("click", function() {
                    closePickers(picker);
                    picker.hidden = !picker.hidden;
                    add.setAttribute("aria-expanded", picker.hidden ? "false" : "true");
                    if(!picker.hidden){
                        picker.querySelector("button").focus();
                    }
                });
                wrap.append(add, picker);
            }

            wrap.classList.toggle("empty", !current.length);
            if(window.parseEmoji){
                window.parseEmoji(wrap);
            }
        }

        function send(emoji){
            Promise.resolve(toggle(emoji)).then(function(list) {
                if(list){
                    current = list;
                    draw();
                }
            }).catch(() => {});
        }

        wrap.update = function(list) {
            current = list || [];
            draw();
        };

        draw();
        return wrap;
    }

    window.watrReactions = { bar: bar };
})();
