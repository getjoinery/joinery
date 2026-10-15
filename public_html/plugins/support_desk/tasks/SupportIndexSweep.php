<?php
/**
 * Support Desk index sweep — hourly.
 *
 * The one caller of SupportDocIndex's builders: a release this node published,
 * received to republish, or upgraded to gets its index on the next run, with
 * no hook in publishing or upgrading (specs/support_chatbot.md, The knowledge
 * index). Re-reads the public pages each run.
 *
 * @version 0.1.0
 */
class SupportIndexSweep implements ScheduledTaskInterface {
	public function run(array $config) {
		try {
			$messages = SupportDocIndex::sweep();
		} catch (\Throwable $e) {
			return array('status' => 'error', 'message' => 'Index sweep failed: ' . $e->getMessage());
		}
		return array('status' => 'success', 'message' => implode(' ', $messages));
	}
}
