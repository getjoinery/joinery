<?php
require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));
require_once(PathHelper::getIncludePath('plugins/persona_browser/data/persona_feed_items_class.php'));

/**
 * Pulls the persona feed from the browser service and appends any posts not
 * already stored (deduped on the service-provided canonical key). New posts'
 * images are cached locally so the feed page renders without a live fetch.
 *
 * Idempotent: re-seeing a post is a no-op (the (owner, persona, dedup_key)
 * unique constraint plus an existence check). A non-ok read (needs-login /
 * service down) leaves the stored feed untouched.
 */
class FetchFeedTask implements ScheduledTaskInterface {

    public function run(array $config) {
        $result = self::fetchResult('facebook');
        // Only fire the success chain (the ad-marking recipe) when this run
        // actually added posts — no new posts, nothing to judge.
        return [
            'status'    => 'success',
            'message'   => $result['message'],
            'run_chain' => $result['new'] > 0,
        ];
    }

    /** Core fetch, callable from the scheduled runner or an on-demand CLI. */
    public static function fetch(string $persona = 'facebook'): string {
        return self::fetchResult($persona)['message'];
    }

    /** Fetch + upsert, returning the count of new posts and a summary line. */
    public static function fetchResult(string $persona = 'facebook'): array {
        $client = new PersonaBrowserClient();
        $feed = $client->get_feed($persona);

        if ($feed['state'] !== 'ok') {
            return [
                'new'     => 0,
                'message' => "persona_browser: skipped ({$feed['state']}"
                           . (!empty($feed['error']) ? ': ' . $feed['error'] : '') . ')',
            ];
        }

        $cache_dir = PathHelper::getIncludePath('plugins/persona_browser/media_cache');
        $new = 0;
        $media_saved = 0;
        $refreshed = 0;

        foreach ($feed['items'] as $item) {
            // Find this post's stored row — by its dedup key, or (if the key
            // changed because the text was reformatted) by author + text.
            $match = self::findStored($persona, $item);

            if ($match) {
                // Heal an already-stored post in place: refresh the text when the
                // extractor now yields a better version (paragraph breaks it once
                // dropped, a de-doubled body) and adopt the current dedup key.
                // First-seen time, cached media, and the ad verdict stay as they
                // are — reformatting the same words changes none of those.
                $changed = false;
                $fresh_msg = (string)$item['message'];
                if ($fresh_msg !== '' && $fresh_msg !== (string)$match->get('pfi_message')) {
                    $match->set('pfi_message', $fresh_msg);
                    $match->set('pfi_image_alt', (string)$item['image_alt']);
                    $changed = true;
                }
                $key = (string)$item['dedup_key'];
                if ($key !== '' && $key !== (string)$match->get('pfi_dedup_key')) {
                    $match->set('pfi_dedup_key', $key);
                    $changed = true;
                }
                if ($changed) { $match->save(); $refreshed++; }
                continue;
            }

            // Cache this new post's images locally.
            $local_media = [];
            foreach ($item['media'] as $file) {
                $dest = $cache_dir . '/' . basename($file);
                if ($client->fetch_media($file, $dest)) {
                    @chmod($dest, 0666);
                    $local_media[] = basename($file);
                    $media_saved++;
                }
            }

            $row = new PersonaFeedItem(NULL);
            $row->set('pfi_owner_user_id', PersonaFeedItem::OWNER_INSTANCE);
            $row->set('pfi_persona', $persona);
            $row->set('pfi_dedup_key', $item['dedup_key']);
            $row->set('pfi_author', mb_substr($item['author'], 0, 255));
            $row->set('pfi_message', $item['message']);
            $row->set('pfi_image_alt', $item['image_alt']);
            $row->set('pfi_link', $item['link']);
            $row->set('pfi_media', json_encode($local_media));
            $row->set('pfi_first_seen_time', gmdate('Y-m-d H:i:s'));
            $row->save();
            $new++;
        }

        return [
            'new'     => $new,
            'message' => "persona_browser: {$new} new post(s), {$media_saved} image(s) cached"
                       . ($refreshed > 0 ? ", {$refreshed} reformatted" : ''),
        ];
    }

    /**
     * Locate the stored row for an incoming item. Matches on the exact dedup
     * key first; failing that, on author + the first 150 characters of the
     * whitespace-normalised body. The second pass is what lets a post with no
     * Facebook permalink (keyed by a hash of its text) be healed in place rather
     * than re-inserted as a duplicate: reformatting the body changes the hash,
     * so the key no longer matches, but the normalised opening still does.
     */
    private static function findStored(string $persona, array $item): ?PersonaFeedItem {
        $byKey = new MultiPersonaFeedItem([
            'owner_user_id' => PersonaFeedItem::OWNER_INSTANCE,
            'persona'       => $persona,
            'dedup_key'     => (string)$item['dedup_key'],
        ]);
        foreach ($byKey as $row) {
            return $row;
        }

        $author = trim((string)$item['author']);
        $needle = mb_substr(self::normalizeText($item['message']), 0, 150);
        // Too little text to match on safely — don't risk healing the wrong row.
        if ($author === '' || mb_strlen($needle) < 40) {
            return null;
        }

        $db = DbConnector::get_instance()->get_db_link();
        $q = $db->prepare("SELECT pfi_persona_feed_item_id AS id, pfi_message AS msg
                           FROM pfi_persona_feed_items
                           WHERE pfi_delete_time IS NULL
                             AND pfi_owner_user_id = ?
                             AND pfi_persona = ?
                             AND pfi_author = ?");
        $q->execute([PersonaFeedItem::OWNER_INSTANCE, $persona, $author]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (mb_substr(self::normalizeText($r['msg']), 0, 150) === $needle) {
                return new PersonaFeedItem((int)$r['id'], true);
            }
        }
        return null;
    }

    /** Lower-cased, whitespace-collapsed text — a formatting-independent key. */
    private static function normalizeText($s): string {
        return strtolower(trim(preg_replace('/\s+/u', ' ', (string)$s)));
    }
}
