/*
 * Reaction buttons (Reaction::render_button(), data/reactions_class.php): a
 * click toggles the reaction through the reaction_toggle action and redraws
 * the icon and count from what comes back.
 *
 * @version 1.0
 */
document.addEventListener("click", function(e) {
	var btn = e.target.closest(".btn-reaction");
	if (!btn) return;
	e.preventDefault();
	btn.disabled = true;
	joineryApi.post("reaction_toggle", {
		entity_type: btn.dataset.entityType,
		entity_id: btn.dataset.entityId,
		reaction_type: btn.dataset.reactionType
	})
	.then(function(data) {
		if (data.action !== undefined) {
			var isActive = (data.action === "reacted");
			btn.dataset.active = isActive ? "true" : "false";
			var icon = btn.querySelector("i");
			if (icon) {
				icon.className = isActive ? btn.dataset.iconActive : btn.dataset.iconInactive;
			}
			if (isActive) { btn.classList.add("active"); } else { btn.classList.remove("active"); }
			var countEl = btn.querySelector(".reaction-count");
			if (countEl && data.count !== undefined) { countEl.textContent = data.count; }
		}
		btn.disabled = false;
	})
	.catch(function() { btn.disabled = false; });
});
