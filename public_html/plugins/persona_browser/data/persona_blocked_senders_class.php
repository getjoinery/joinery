<?php

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));

class PersonaBlockedSenderException extends SystemBaseException {}

/**
 * A feed creator the owner never wants to see again. Matching is by author
 * display name (the only stable identity the extractor yields), compared
 * case-insensitively at display time — blocked authors' posts keep being
 * captured but are filtered out of the feed page. pbs_owner_user_id is 0 for
 * the single shared instance feed, mirroring PersonaFeedItem::OWNER_INSTANCE.
 */
class PersonaBlockedSender extends SystemBase {
    public static $prefix = 'pbs';
    public static $tablename = 'pbs_persona_blocked_senders';
    public static $pkey_column = 'pbs_blocked_sender_id';

    public static $field_specifications = array(
        'pbs_blocked_sender_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true, 'is_primary_key'=>true),
        'pbs_owner_user_id' => array('type'=>'int4', 'is_nullable'=>false, 'default'=>'0', 'unique_with'=>array('pbs_persona', 'pbs_author')),
        'pbs_persona' => array('type'=>'varchar(32)', 'is_nullable'=>false, 'required'=>true),
        'pbs_author' => array('type'=>'varchar(255)', 'is_nullable'=>false, 'required'=>true),
        // Who decided: 'manual' = the owner clicked Block sender; 'auto' = the
        // ad-judging pipeline hit the repeat-advertiser threshold.
        'pbs_source' => array('type'=>'varchar(10)', 'is_nullable'=>false, 'default'=>'manual'),
        // Short human-readable why, shown on the Blocked Senders admin page
        // (e.g. '3 posts judged ads'). Empty for manual blocks.
        'pbs_note' => array('type'=>'varchar(255)'),
        'pbs_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
        'pbs_update_time' => array('type'=>'timestamp(6)', 'is_nullable'=>true),
        'pbs_delete_time' => array('type'=>'timestamp(6)', 'is_nullable'=>true),
    );

    /**
     * Lower-cased author names blocked for this persona, keyed for O(1) lookup:
     * array('some author' => true, ...). Display names vary in casing between
     * captures, so callers compare with mb_strtolower().
     */
    public static function blocked_author_set(int $owner_user_id, string $persona): array {
        $blocked = new MultiPersonaBlockedSender([
            'owner_user_id' => $owner_user_id,
            'persona'       => $persona,
            'deleted'       => false,
        ]);
        $set = array();
        foreach ($blocked as $row) {
            $set[mb_strtolower(trim((string)$row->get('pbs_author')))] = true;
        }
        return $set;
    }

    /**
     * Block an author, or do nothing if already blocked (an undeleted row
     * exists). A previously unblocked (soft-deleted) row is revived rather
     * than duplicated — the unique constraint spans deleted rows too.
     */
    public static function block(int $owner_user_id, string $persona, string $author): void {
        $author = trim($author);
        $existing = new MultiPersonaBlockedSender([
            'owner_user_id' => $owner_user_id,
            'persona'       => $persona,
            'author'        => $author,
        ]);
        foreach ($existing as $row) {
            if ($row->get('pbs_delete_time')) {
                // The owner re-blocking by hand owns the row from here on,
                // whatever originally created it.
                $row->set('pbs_source', 'manual');
                $row->set('pbs_note', '');
                $row->undelete();
            }
            return;
        }

        $row = new PersonaBlockedSender(NULL);
        $row->set('pbs_owner_user_id', $owner_user_id);
        $row->set('pbs_persona', $persona);
        $row->set('pbs_author', $author);
        $row->save();
    }

    /**
     * Block an author on the system's initiative (the repeat-advertiser
     * threshold). Declines — returns FALSE — if ANY row for this author
     * already exists, live or soft-deleted: a live row means already blocked,
     * and a soft-deleted row means the owner unblocked this author once, a
     * decision automation never overrides. Returns TRUE when a block was added.
     */
    public static function auto_block(int $owner_user_id, string $persona, string $author, string $note): bool {
        $author = trim($author);
        if ($author === '') return false;
        // Case-insensitive existence check — captures vary an author's casing,
        // and the display filter compares lowercased, so the decision must too.
        $existing = new MultiPersonaBlockedSender([
            'owner_user_id' => $owner_user_id,
            'persona'       => $persona,
        ]);
        foreach ($existing as $row) {
            if (mb_strtolower(trim((string)$row->get('pbs_author'))) === mb_strtolower($author)) {
                return false;
            }
        }

        $row = new PersonaBlockedSender(NULL);
        $row->set('pbs_owner_user_id', $owner_user_id);
        $row->set('pbs_persona', $persona);
        $row->set('pbs_author', $author);
        $row->set('pbs_source', 'auto');
        $row->set('pbs_note', mb_substr($note, 0, 255));
        $row->save();
        return true;
    }
}

class MultiPersonaBlockedSender extends SystemMultiBase {
    protected static $model_class = 'PersonaBlockedSender';

    protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];
        if (isset($this->options['owner_user_id'])) {
            $filters['pbs_owner_user_id'] = [$this->options['owner_user_id'], PDO::PARAM_INT];
        }
        if (isset($this->options['persona'])) {
            $filters['pbs_persona'] = [$this->options['persona'], PDO::PARAM_STR];
        }
        if (isset($this->options['author'])) {
            $filters['pbs_author'] = [$this->options['author'], PDO::PARAM_STR];
        }
        return $this->_get_resultsv2('pbs_persona_blocked_senders', $filters, $this->order_by, $only_count, $debug);
    }
}
