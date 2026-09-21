<?php
/**
 * The offload tick keeps running while any offloaded file exists.
 *
 * The daily file-store check (CloudStoreInventory) runs inside the offload
 * tick (CloudOffloadRun), and the tick deactivates itself only when no store
 * is offloading or draining AND no file row says its bytes live in a cloud
 * store. A site that offloaded its files and then paused the store has the
 * tick switched off from before that rule existed, and nothing else switches
 * it back on: the tick reactivates itself on the way out of a store action,
 * not on its own. So this wakes it once, where offloaded files exist.
 *
 * Idempotent: an active tick is left as it is, and a site with no offloaded
 * file is not touched.
 */
function cloud_offload_tick_while_files_offloaded() {
    $db = DbConnector::get_instance()->get_db_link();
    $q = $db->query("SELECT COUNT(*) FROM fbb_file_blobs WHERE fbb_storage_driver = 'cloud'");
    $rows = (int)$q->fetchColumn();
    if ($rows === 0) {
        echo "  no offloaded files: the offload tick is left as it is\n";
        return;
    }
    CloudStorageLifecycle::ensureTickActive();
    echo "  {$rows} offloaded file(s): the offload tick (CloudOffloadRun) is active for the daily file-store check\n";
}
?>
