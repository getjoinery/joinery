<?php
/** @joinery-test
 * name: node_removal_backups_message
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * What Remove Permanently says when a node's backups are still stored.
 *
 * The target's Stored Backups panel deletes a site's backups, but only once
 * new backups no longer go to its folder, which is what hiding the node does.
 * The refusal used to name the panel and nothing else, so a person with the
 * node still shown found no way to delete them. It names each target, folder
 * and page, and tells a node still shown to hide first
 * (NodeRemoval::backups_message, 1.4).
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

section('Where the backups are, and what to do first');

$bk = array('count' => 7, 'unchecked' => array(), 'where' => array(
	array('target_id' => 3, 'target' => 'Backblaze B2 Joinery', 'folder' => 'download-test-container', 'count' => 7),
));
$shown = NodeRemoval::backups_message($bk, false);
check(strpos($shown, '7 offsite backup files') !== false, 'it counts the files', $shown);
check(strpos($shown, 'folder download-test-container') !== false && strpos($shown, '"Backblaze B2 Joinery"') !== false,
	'it names the folder and the target', $shown);
check(strpos($shown, '/admin/server_manager/targets?bkt_backup_target_id=3') !== false, 'and the page that deletes them', $shown);
check(strpos($shown, 'Hide it from the dashboard first') !== false, 'a node still shown is told to hide first', $shown);

$hidden = NodeRemoval::backups_message($bk, true);
check(strpos($hidden, 'Hide it') === false && strpos($hidden, 'Delete all for download-test-container') !== false,
	'a hidden node is told only where to delete them', $hidden);

$two = NodeRemoval::backups_message(array('count' => 3, 'where' => array(
	array('target_id' => 3, 'target' => 'A', 'folder' => 'site', 'count' => 2),
	array('target_id' => 9, 'target' => 'B', 'folder' => 'site', 'count' => 1),
)), true);
check(substr_count($two, 'Stored Backups') === 2 && strpos($two, 'bkt_backup_target_id=9') !== false, 'each target holding some is named', $two);

$one = NodeRemoval::backups_message(array('count' => 1, 'where' => array()), true);
check(strpos($one, '1 offsite backup file.') !== false, 'one file reads in the singular', $one);

harness_finish();
