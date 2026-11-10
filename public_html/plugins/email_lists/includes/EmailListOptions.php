<?php
/**
 * EmailListOptions — option sources for the plugin's settings (options_from).
 *
 * @version 1.0
 */
class EmailListOptions {

	/** Mailing list services, discovered from the provider classes. */
	public static function mailingListServices(): array {
		return MailingListService::getAvailableServices();
	}

	/** Mailing lists, with the everyone option the sending paths understand. */
	public static function mailingLists(): array {
		$lists = new MultiMailingList(array('deleted' => false), NULL, NULL, NULL);
		$lists->load();

		return array('all' => 'All Lists') + $lists->get_dropdown_array();
	}
}
