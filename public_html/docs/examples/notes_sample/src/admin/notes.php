<?php
/** The admin page: every note, newest first, and a form to add one. */
if (SandboxPage::isPost() && trim((string)SandboxPage::field('text')) !== '') {
	$note = new Note();
	$note->set('nte_text', (string)SandboxPage::field('text'));
	$note->set('nte_usr_user_id', (int)SessionControl::get_instance()->get_user_id());
	$note->save();
	SandboxPage::redirect('notes', array('added' => $note->key));
	return;
}
if (SandboxPage::query('added')) {
	echo '<p class="alert alert-success">Note added.</p>';
}
echo '<table class="table"><tr><th>Note</th></tr>';
foreach (new MultiNote(array('deleted' => false), array('nte_note_id' => 'DESC'), 50) as $note) {
	echo '<tr><td>' . htmlspecialchars($note->line()) . '</td></tr>';
}
echo '</table>';
?>
<form method="post">
	<label>New note <input name="text" required></label>
	<button type="submit" class="btn btn-primary">Add</button>
</form>
<img src="assets/note.png" alt="">
