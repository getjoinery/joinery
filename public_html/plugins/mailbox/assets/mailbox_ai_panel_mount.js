/*
 * Mounts the AI panel in the mail reader (views/profile/mailbox.php). Loaded,
 * not deferred, after ai_panel.js, mailbox_reader.js and (for Fortress
 * mailboxes) mailbox_device_ai.js.
 *
 * @version 1.0
 */
JoineryAiPanel.mount({
	area: 'mailbox',
	hostSection: window.MailboxDeviceAi ? window.MailboxDeviceAi.section() : null,
	getContext: function () {
		return { mailbox: window.MailboxReader ? window.MailboxReader.currentAddress() : '' };
	},
	anchor: document.getElementById('mbx-ai-panel-anchor'),
	// The reader's right column: the panel docks above the contact panel
	// and shares the column with it. The reader drops that column below
	// 1100px, and the panel notices and moves into its own slide-over, so
	// the AI button keeps working on a phone.
	container: document.getElementById('mbx-context-ai')
});
