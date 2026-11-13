/*
 * /admin/admin_page: Add existing shows its section, and the layout list's
 * rows can be dragged into a new order (adm/admin_page.php).
 *
 * @version 1.0
 */
(function(){
	var list = document.getElementById('layoutList');
	if (!list) return;
	var dragSrc = null;
	Array.from(list.querySelectorAll('li[data-id]')).forEach(function(li){
		li.setAttribute('draggable', 'true');
		li.addEventListener('dragstart', function(e){
			dragSrc = li;
			li.classList.add('opacity-50');
			e.dataTransfer.effectAllowed = 'move';
		});
		li.addEventListener('dragover', function(e){
			e.preventDefault();
			e.dataTransfer.dropEffect = 'move';
			var rect = li.getBoundingClientRect();
			var before = (e.clientY - rect.top) < rect.height / 2;
			if (dragSrc && dragSrc !== li) {
				if (before) list.insertBefore(dragSrc, li);
				else list.insertBefore(dragSrc, li.nextSibling);
			}
		});
		li.addEventListener('dragend', function(){
			li.classList.remove('opacity-50');
			dragSrc = null;
		});
	});
})();
(function () {
var toggle = document.getElementById('toggleAddExisting');
if (!toggle) return;
toggle.addEventListener('click', function(e) {
	e.preventDefault();
	var section = document.getElementById('addExistingSection');
	section.style.display = section.style.display === 'none' ? 'block' : 'none';
});
})();
