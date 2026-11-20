<?php
/** The framed board: the plugin's own document, script and styles inline. */
$notes = array();
foreach (new MultiNote(array('deleted' => false), array('id' => 'DESC'), 20) as $note) {
	$notes[] = array('id' => (int)$note->key, 'line' => $note->line());
}
?><!doctype html>
<html><head><meta charset="utf-8"><style>
body { font-family: sans-serif; margin: 1rem; }
li { cursor: pointer; padding: .25rem 0; }
</style></head><body>
<ul id="notes"></ul>
<script>
var notes = <?php echo json_encode($notes, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
var list = document.getElementById('notes');
notes.forEach(function (n) {
	var li = document.createElement('li');
	li.textContent = n.line;
	li.onclick = function () { parent.postMessage({type: 'sbx-call', id: n.id, handler: 'pin', data: {id: n.id}}, '*'); };
	list.appendChild(li);
});
// The frame is never reloaded or navigated (its only address is core's
// framed route, loaded by the outer page): it updates itself.
window.addEventListener('message', function (e) {
	// Only core's bridge, on the page holding this frame, answers.
	if (e.source !== parent) { return; }
	if (!e.data || e.data.type !== 'sbx-answer' || !e.data.ok) { return; }
	var items = list.querySelectorAll('li');
	notes.forEach(function (n, i) {
		if (n.id === e.data.id && items[i].textContent.indexOf('[pinned] ') !== 0) {
			items[i].textContent = '[pinned] ' + items[i].textContent;
		}
	});
});
parent.postMessage({type: 'sbx-height', height: document.body.scrollHeight + 40}, '*');
</script>
</body></html>
