<?php
/**
 * Support Desk sync hook — runs on every plugin sync and at activation.
 *
 * Two jobs, both idempotent:
 *   1. make the reserved user every support transcript belongs to, when there
 *      is none (SupportDesk::ensureSystemUser());
 *   2. index this node's own release when it has no index yet, so the desk
 *      answers from the moment it is switched on. The hourly sweep
 *      (tasks/SupportIndexSweep) indexes the rest of what the node holds.
 *
 * @return string[] messages for the sync report
 */
function support_desk_sync() {
	// At activation the plugin is not active yet, so its classes do not
	// resolve by name; the hook loads what it uses.
	foreach (array('data/doc_releases_class.php', 'data/doc_sections_class.php', 'data/faq_entries_class.php',
			'includes/SupportDesk.php', 'includes/SupportDocIndex.php') as $file) {
		require_once(PathHelper::getIncludePath('plugins/support_desk/' . $file));
	}
	$messages = array();
	$made = SupportDesk::ensureSystemUser();
	if ($made !== '') {
		$messages[] = $made;
	}
	$own = (string)LibraryFunctions::get_joinery_version();
	if (SupportDocIndex::isVersion($own) && SupportDocRelease::forRelease($own) === null) {
		$r = SupportDocIndex::archivePath($own) !== null
			? SupportDocIndex::buildFromArchive($own)
			: SupportDocIndex::buildFromTree($own);
		$messages[] = $r['message'];
	}
	return $messages;
}
