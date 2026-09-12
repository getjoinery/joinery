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
 *
 * The counterpart list is PersonaAllowedSender. An author is on at most one
 * of the two: allowing lifts a block, and blocking by hand lifts an allow.
 * Automation (auto_block) never touches an allowed author.
 *
 * @version 1.1 - unblock(); auto_block defers to the allow list rather than
 *                to the trace of a past unblock
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
        // Short human-readable why, shown on the Senders admin page
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
     * Block an author by the owner's hand, or do nothing if already blocked.
     * The owner's word wins: an allow on this author is lifted. A soft-deleted
     * row is revived rather than duplicated.
     */
    public static function block(int $owner_user_id, string $persona, string $author): void {
        $author = trim($author);
        if ($author === '') return;

        PersonaAllowedSender::disallow($owner_user_id, $persona, $author);

        $row = self::find($owner_user_id, $persona, $author, false);
        if ($row !== null) {
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
     * Lift a block. The author is an ordinary sender again — which means the
     * repeat-advertiser rule can block them again later. An owner who wants
     * a sender kept for good puts them on the allow list instead.
     */
    public static function unblock(int $owner_user_id, string $persona, string $author): void {
        $row = self::find($owner_user_id, $persona, $author, true);
        if ($row !== null) {
            $row->soft_delete();
        }
    }

    /**
     * Block an author on the system's initiative (the repeat-advertiser
     * threshold). Declines — returns FALSE — when the author is already
     * blocked, or is on the allow list: that list is the owner's standing
     * instruction, and automation never overrides it. Returns TRUE when a
     * block was added.
     */
    public static function auto_block(int $owner_user_id, string $persona, string $author, string $note): bool {
        $author = trim($author);
        if ($author === '') return false;
        if (PersonaAllowedSender::is_allowed($owner_user_id, $persona, $author)) return false;

        $row = self::find($owner_user_id, $persona, $author, false);
        if ($row !== null) {
            if (!$row->get('pbs_delete_time')) return false;
            $row->set('pbs_source', 'auto');
            $row->set('pbs_note', mb_substr($note, 0, 255));
            $row->undelete();
            return true;
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

    /**
     * The row for this author, matched case-insensitively — captures vary an
     * author's casing, and the display filter compares lowercased, so every
     * decision here must too. With $live_only a soft-deleted row does not
     * count; without it the deleted row is returned so a caller can revive it
     * instead of tripping the unique key.
     */
    private static function find(int $owner_user_id, string $persona, string $author, bool $live_only): ?PersonaBlockedSender {
        $wanted = mb_strtolower(trim($author));
        $options = ['owner_user_id' => $owner_user_id, 'persona' => $persona];
        if ($live_only) $options['deleted'] = false;
        $rows = new MultiPersonaBlockedSender($options);
        foreach ($rows as $row) {
            if (mb_strtolower(trim((string)$row->get('pbs_author'))) === $wanted) {
                return $row;
            }
        }
        return null;
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
