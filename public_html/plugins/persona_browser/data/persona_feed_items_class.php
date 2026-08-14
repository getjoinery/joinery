<?php

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));

class PersonaFeedItemException extends SystemBaseException {}

/**
 * One captured post from a persona's social feed. Rows accumulate across the
 * hourly fetch (see FetchFeedTask); the (owner, persona, dedup_key) triple is
 * unique so re-seeing a post is a no-op. pfi_owner_user_id is 0 for the single
 * shared instance feed (the experiment reads one Facebook account).
 *
 * pfi_first_seen_time is the authoritative displayed date: Facebook obscures a
 * post's real timestamp, so "when our fetch first captured it" is the reliable,
 * correctly-ordered signal. pfi_media holds a JSON array of locally-cached
 * image filenames served via /profile/persona_browser/media.
 */
class PersonaFeedItem extends SystemBase {
    public static $prefix = 'pfi';
    public static $tablename = 'pfi_persona_feed_items';
    public static $pkey_column = 'pfi_persona_feed_item_id';

    const OWNER_INSTANCE = 0;

    public static $field_specifications = array(
        'pfi_persona_feed_item_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true, 'is_primary_key'=>true),
        'pfi_owner_user_id' => array('type'=>'int4', 'is_nullable'=>false, 'default'=>'0', 'unique_with'=>array('pfi_persona', 'pfi_dedup_key')),
        'pfi_persona' => array('type'=>'varchar(32)', 'is_nullable'=>false, 'required'=>true),
        'pfi_dedup_key' => array('type'=>'varchar(255)', 'is_nullable'=>false, 'required'=>true),
        'pfi_author' => array('type'=>'varchar(255)'),
        'pfi_message' => array('type'=>'text'),
        'pfi_image_alt' => array('type'=>'text'),
        'pfi_link' => array('type'=>'text'),
        'pfi_media' => array('type'=>'text'),
        // Advertisement verdict from the MarkAdvertisementsJob AI recipe.
        // pfi_is_ad NULL = not yet judged; TRUE/FALSE = judged.
        'pfi_is_ad' => array('type'=>'bool', 'is_nullable'=>true),
        'pfi_ad_reason' => array('type'=>'text'),
        'pfi_ad_judged_time' => array('type'=>'timestamp(6)', 'is_nullable'=>true),
        'pfi_ad_model' => array('type'=>'varchar(80)'),
        'pfi_first_seen_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
        'pfi_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
        'pfi_update_time' => array('type'=>'timestamp(6)', 'is_nullable'=>true),
        'pfi_delete_time' => array('type'=>'timestamp(6)', 'is_nullable'=>true),
    );

    /**
     * Retention: delete a post (and its cached images) a set number of days
     * after it was first captured. Enforced by the shared RetentionSweep task,
     * which discovers this declaration — no per-plugin task. The method form is
     * used because expiry also reclaims files on disk. 0 in the setting = never.
     */
    public static $retention_policy = array(
        'label'          => 'Persona feed posts',
        'purge_method'   => 'purgeExpired',
        'window_setting' => 'persona_browser_post_retention_days',
    );

    /** Locally-cached media filenames for this post. */
    public function media_files(): array {
        return self::decode_media($this->get('pfi_media'));
    }

    /** Decode a stored pfi_media JSON array into a list of filenames. */
    private static function decode_media($raw): array {
        if (!$raw) return array();
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : array();
    }

    /**
     * Permanently delete every post first captured more than $days ago, and
     * unlink each cached image no surviving post still references. A cached
     * image is shared by filename (same source URL → same sha1 name), so a file
     * is only removed once nothing live points at it. Returns the sweep result
     * contract RetentionSweep expects: array('removed' => int, 'message' => str).
     */
    public static function purgeExpired($days): array {
        $days = (int)$days;
        if ($days <= 0) {
            return array('removed' => 0, 'message' => 'retention off');
        }

        $db = DbConnector::get_instance()->get_db_link();
        $q = $db->prepare(
            "SELECT pfi_persona_feed_item_id AS id, pfi_media AS media
               FROM pfi_persona_feed_items
              WHERE pfi_first_seen_time < now() - (INTERVAL '1 day' * :days)");
        $q->execute(array(':days' => $days));
        $expiring = $q->fetchAll(PDO::FETCH_ASSOC);
        if (!$expiring) {
            return array('removed' => 0, 'message' => 'no posts past the window');
        }

        $expiring_ids = array();
        $media_candidates = array();
        foreach ($expiring as $r) {
            $expiring_ids[] = (int)$r['id'];
            foreach (self::decode_media($r['media']) as $f) {
                $media_candidates[basename($f)] = true;
            }
        }

        // Files any surviving post (not in the expiring set) still needs.
        $still_referenced = array();
        if ($media_candidates) {
            $survivors = $db->query(
                "SELECT pfi_media FROM pfi_persona_feed_items
                  WHERE pfi_persona_feed_item_id NOT IN (" . implode(',', $expiring_ids) . ")"
            )->fetchAll(PDO::FETCH_COLUMN);
            foreach ($survivors as $m) {
                foreach (self::decode_media($m) as $f) {
                    $still_referenced[basename($f)] = true;
                }
            }
        }

        $cache_dir = PathHelper::getIncludePath('plugins/persona_browser/media_cache');
        $files_removed = 0;
        foreach (array_keys($media_candidates) as $file) {
            if (isset($still_referenced[$file])) continue;
            $path = $cache_dir . '/' . $file;
            if (is_file($path) && @unlink($path)) {
                $files_removed++;
            }
        }

        $rows_removed = 0;
        foreach ($expiring_ids as $id) {
            $row = new self($id, true);
            if ($row->key) {
                $row->permanent_delete();
                $rows_removed++;
            }
        }

        return array(
            'removed' => $rows_removed,
            'message' => $rows_removed . ' post(s), ' . $files_removed . ' media file(s)',
        );
    }
}

class MultiPersonaFeedItem extends SystemMultiBase {
    protected static $model_class = 'PersonaFeedItem';

    protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];
        if (isset($this->options['owner_user_id'])) {
            $filters['pfi_owner_user_id'] = [$this->options['owner_user_id'], PDO::PARAM_INT];
        }
        if (isset($this->options['persona'])) {
            $filters['pfi_persona'] = [$this->options['persona'], PDO::PARAM_STR];
        }
        if (isset($this->options['dedup_key'])) {
            $filters['pfi_dedup_key'] = [$this->options['dedup_key'], PDO::PARAM_STR];
        }
        return $this->_get_resultsv2('pfi_persona_feed_items', $filters, $this->order_by, $only_count, $debug);
    }
}
