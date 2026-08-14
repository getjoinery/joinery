<?php
require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));
require_once(PathHelper::getIncludePath('plugins/persona_browser/data/persona_feed_items_class.php'));
require_once(PathHelper::getIncludePath('plugins/persona_browser/data/persona_stories_class.php'));

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
                if (self::applyCaptureMeta($match, $item)) {
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
            self::applyCaptureMeta($row, $item);
            $row->save();
            $new++;
        }

        $stories_note = self::syncStories($persona, $feed['stories'] ?? [], $client, $cache_dir);

        return [
            'new'     => $new,
            'message' => "persona_browser: {$new} new post(s), {$media_saved} image(s) cached"
                       . ($refreshed > 0 ? ", {$refreshed} reformatted" : '')
                       . $stories_note,
        ];
    }

    /**
     * Mirror the capture's Stories tray into pss_persona_stories: insert what
     * appeared, refresh what's still showing, permanently delete what's gone
     * (stories expire within a day, so a vanished entry is a dead link). A
     * capture with no tray leaves the stored set alone — the scroll may simply
     * have started past it. Returns a summary fragment for the fetch message.
     */
    private static function syncStories(string $persona, array $stories, PersonaBrowserClient $client, string $cache_dir): string {
        if (!$stories) return '';

        $seen_keys = [];
        $new = 0;
        foreach ($stories as $pos => $story) {
            $key = (string)$story['story_key'];
            $seen_keys[$key] = true;

            $existing = null;
            $lookup = new MultiPersonaStory([
                'owner_user_id' => PersonaFeedItem::OWNER_INSTANCE,
                'persona'       => $persona,
                'story_key'     => $key,
                'deleted'       => false,
            ]);
            foreach ($lookup as $row) { $existing = $row; break; }

            $target = $existing ?: new PersonaStory(NULL);
            if (!$existing) {
                $target->set('pss_owner_user_id', PersonaFeedItem::OWNER_INSTANCE);
                $target->set('pss_persona', $persona);
                $target->set('pss_story_key', $key);
                $target->set('pss_first_seen_time', gmdate('Y-m-d H:i:s'));
                $new++;
            }
            $target->set('pss_author', mb_substr((string)$story['author'], 0, 255));
            $target->set('pss_link', (string)$story['link']);
            $target->set('pss_position', (int)$pos);
            $target->set('pss_last_seen_time', gmdate('Y-m-d H:i:s'));
            foreach (['preview' => 'pss_preview_media', 'avatar' => 'pss_avatar_media'] as $k => $col) {
                $file = basename((string)$story[$k]);
                if ($file === '') continue;
                if ($client->fetch_media($file, $cache_dir . '/' . $file)) {
                    @chmod($cache_dir . '/' . $file, 0666);
                    $target->set($col, $file);
                }
            }
            $target->save();
        }

        // Entries the tray no longer shows are expired — remove them and any
        // cached image nothing else references.
        $current = new MultiPersonaStory([
            'owner_user_id' => PersonaFeedItem::OWNER_INSTANCE,
            'persona'       => $persona,
            'deleted'       => false,
        ]);
        $expired = [];
        $kept_files = [];
        foreach ($current as $row) {
            if (isset($seen_keys[(string)$row->get('pss_story_key')])) {
                foreach ($row->media_files() as $f) $kept_files[$f] = true;
            } else {
                $expired[] = $row;
            }
        }
        $db = DbConnector::get_instance()->get_db_link();
        foreach ($expired as $row) {
            foreach ($row->media_files() as $f) {
                if (isset($kept_files[$f])) continue;
                // The media cache is shared with post images — never remove a
                // file a stored post still references.
                $q = $db->prepare('SELECT 1 FROM pfi_persona_feed_items WHERE pfi_media LIKE ? LIMIT 1');
                $q->execute(['%' . basename($f) . '%']);
                if ($q->fetchColumn()) continue;
                @unlink($cache_dir . '/' . basename($f));
            }
            $row->permanent_delete();
        }

        return ', ' . count($seen_keys) . ' current stor' . (count($seen_keys) === 1 ? 'y' : 'ies')
             . ($new > 0 ? " ({$new} new)" : '');
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

    /**
     * Copy the extractor's capture metadata (relation, group, author link,
     * verified badge, post type, audience, engagement counts) onto a row —
     * used for new rows and to heal rows stored before these fields existed.
     * A value the current capture didn't yield (empty string / NULL) never
     * overwrites one already stored; engagement counts do refresh, since they
     * grow over time. For group posts the author heals to the actual person
     * once the group name moves to pfi_group_name. Returns whether anything
     * changed; does not save.
     */
    private static function applyCaptureMeta(PersonaFeedItem $row, array $item): bool {
        $changed = false;

        $strings = [
            'pfi_relation'    => (string)$item['relation'],
            'pfi_group_name'  => mb_substr((string)$item['group_name'], 0, 255),
            'pfi_author_link' => (string)$item['author_link'],
            'pfi_post_type'   => (string)$item['post_type'],
            'pfi_audience'    => mb_substr((string)$item['audience'], 0, 40),
        ];
        foreach ($strings as $col => $val) {
            if ($val !== '' && $val !== (string)$row->get($col)) {
                $row->set($col, $val);
                $changed = true;
            }
        }

        $author = mb_substr(trim((string)$item['author']), 0, 255);
        if ($author !== '' && $author !== (string)$row->get('pfi_author')) {
            $row->set('pfi_author', $author);
            $changed = true;
        }

        if ($item['author_verified'] !== null) {
            $stored = $row->get('pfi_author_verified');
            if ($stored === null || $stored === '' || (bool)$stored !== (bool)$item['author_verified']) {
                $row->set('pfi_author_verified', (bool)$item['author_verified']);
                $changed = true;
            }
        }

        $counts = [
            'pfi_reactions'     => $item['reactions'],
            'pfi_comment_count' => $item['comment_count'],
            'pfi_share_count'   => $item['share_count'],
        ];
        foreach ($counts as $col => $val) {
            if ($val !== null && (string)$val !== (string)$row->get($col)) {
                $row->set($col, (int)$val);
                $changed = true;
            }
        }

        return $changed;
    }

    /** Lower-cased, whitespace-collapsed text — a formatting-independent key. */
    private static function normalizeText($s): string {
        return strtolower(trim(preg_replace('/\s+/u', ' ', (string)$s)));
    }
}
