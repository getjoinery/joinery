<?php
/** The sample's Note, with a method of its own. Its fields are the manifest's. */
class Note extends Sbx_notes_sample_Note {

	/** The note as one line. */
	public function line(): string {
		return ($this->get('pinned') ? '[pinned] ' : '') . (string)$this->get('text');
	}

	/** A plugin's own rule, run in the fence before the save is asked for. */
	function prepare() {
		if (trim((string)$this->get('text')) === '') {
			throw new RuntimeException('A note needs some text.');
		}
	}
}
