// runs again on every visit (pages load in place), so everything stays inside this function
(function(){

// small helpers for the admin panel. every form still works without this

// "are you sure" on deletes, bans and the like
document.querySelectorAll("form[data-confirm]").forEach(function(form) {
    form.addEventListener("submit", function(event) {
        if(!confirm(form.dataset.confirm)){
            event.preventDefault();
        }
    });
});

// ---------- images: preview before saving ----------

document.querySelectorAll("input[type=file][data-preview]").forEach(function(input) {
    input.addEventListener("change", function() {
        let target = document.querySelector(input.dataset.preview);
        if(!target || !input.files[0]){
            return;
        }
        let img = document.createElement("img");
        img.src = URL.createObjectURL(input.files[0]);
        img.alt = "";
        target.replaceChildren(img);
    });
});

// ---------- tracks: read the length from the audio ----------

let audioPreview = document.getElementById("audioPreview");
let durationInput = document.getElementById("duration");

if(audioPreview && durationInput){
    let fillDuration = function() {
        if(isFinite(audioPreview.duration) && audioPreview.duration > 0){
            let seconds = Math.round(audioPreview.duration);
            durationInput.value = Math.floor(seconds / 60) + ":" + String(seconds % 60).padStart(2, "0");
        }
    };

    audioPreview.addEventListener("loadedmetadata", fillDuration);

    document.getElementById("audioFile").addEventListener("change", function() {
        if(this.files[0]){
            audioPreview.src = URL.createObjectURL(this.files[0]);
            audioPreview.hidden = false;
        }
    });

    document.getElementById("filePath").addEventListener("change", function() {
        if(this.value.trim()){
            audioPreview.src = this.value.trim();
            audioPreview.hidden = false;
        }
    });
}

// ---------- ai providers: show the options for the chosen api ----------

let typeSelect = document.querySelector("#providerForm #type");

function showTypeOptions(){
    let type = typeSelect.value;

    document.querySelectorAll("#providerForm [data-for]").forEach(function(node) {
        let match = node.dataset.for === type;
        node.hidden = !match;
        if(node.tagName === "FIELDSET"){
            node.disabled = !match; // hidden options don't get submitted
        }
    });

    document.getElementById("base_url").placeholder = typeSelect.selectedOptions[0].dataset.url;
}

if(typeSelect){
    typeSelect.addEventListener("change", showTypeOptions);
    showTypeOptions();
}

// ---------- ai providers: list what the server has ----------

let fetchButton = document.getElementById("fetchModels");

if(fetchButton){
    fetchButton.addEventListener("click", function() {
        let box = document.getElementById("remoteModels");
        box.hidden = false;
        box.className = "remoteModels muted";
        box.textContent = "Asking the provider...";
        fetchButton.disabled = true;

        fetch(fetchButton.dataset.url).then(function(response) {
            return response.json();
        }).then(function(data) {
            if(data.status !== "okay"){
                throw new Error(data.message || "Couldn't get the list.");
            }

            box.className = "remoteModels";
            box.replaceChildren();

            if(!data.models.length){
                box.textContent = "The provider says it has no models.";
                return;
            }

            let note = document.createElement("p");
            note.className = "hint";
            note.textContent = data.models.length + " available. Add the ones people should be able to pick.";
            box.append(note);

            let list = document.createElement("ul");
            data.models.forEach(function(model) {
                let item = document.createElement("li");
                let name = document.createElement("span");
                name.className = "mono";
                name.textContent = model.name;
                item.append(name);

                if(model.label && model.label !== model.name){
                    let label = document.createElement("span");
                    label.className = "muted";
                    label.textContent = model.label;
                    item.append(label);
                }

                if(model.added){
                    let added = document.createElement("span");
                    added.className = "badge";
                    added.textContent = "added";
                    item.append(added);
                } else {
                    let add = document.createElement("button");
                    add.type = "button";
                    add.className = "button quiet";
                    add.textContent = "Add";
                    add.addEventListener("click", function() {
                        let form = document.getElementById("addModel");
                        form.querySelector("[name=name]").value = model.name;
                        form.querySelector("[name=label]").value = model.label || model.name;
                        form.submit();
                    });
                    item.append(add);
                }

                list.append(item);
            });
            box.append(list);
        }).catch(function(error) {
            box.className = "remoteModels error";
            box.textContent = error.message;
        }).finally(function() {
            fetchButton.disabled = false;
        });
    });
}

})();
