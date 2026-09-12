<?php

class PersonaAllowedSenderException extends SystemBaseException {}

/**
 * A feed creator the owner always wants to see, whatever the AI thinks of
 * their posts. An allowed sender is never auto-blocked, their posts are never
 * sent to the ad-judging recipe, and the feed shows their posts even when
 * "hide ads" is on and an earlier verdict called one an ad.
 *
 * The list is per persona and lives here, not in contacts: a Facebook display
 * name is the only identity the feed yields, and it means nothing outside this
 * feed. Matching is case-insensitive, like the block list — captures vary a
 * name's casing. pas_owner_user_id is 0 for the shared instance feed
 * (PersonaFeedItem::OWNER_INSTANCE).
 *
 * @version 1.0
 */
class PersonaAllowedSender extends SystemBase {
    public static $prefix = 'pas';
    public static $tablename = 'pas_persona_allowed_senders';
    public static $pkey_column = 'pas_allowed_sender_id';

    public static $field_specifications = array(
        'pas_allowed_sender_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true, 'is_primary_key'=>true),
        'pas_owner_user_id' => array('type'=>'int4', 'is_nullable'=>false, 'default'=>'0', 'unique_with'=>array('pas_persona', 'pas_author')),
        'pas_persona' => array('type'=>'varchar(32)', 'is_nullable'=>false, 'required'=>true),
        'pas_author' => array('type'=>'varchar(255)', 'is_nullable'=>false, 'required'=>true),
        // Optional reminder to the owner of why this sender is on the list.
        'pas_note' => array('type'=>'varchar(255)'),
        'pas_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
        'pas_update_time' => array('type'=>'timestamp(6)', 'is_nullable'=>true),
        'pas_delete_time' => array('type'=>'timestamp(6)', 'is_nullable'=>true),
    );

    /**
     * Lower-cased author names allowed for this persona, keyed for O(1)
     * lookup: array('some author' => true, ...). Callers compare with
     * mb_strtolower(trim()).
     */
    public static function allowed_author_set(int $owner_user_id, string $persona): array {
        $allowed = new MultiPersonaAllowedSender([
            'owner_user_id' => $owner_user_id,
            'persona'       => $persona,
            'deleted'       => false,
        ]);
        $set = array();
        foreach ($allowed as $row) {
            $set[mb_strtolower(trim((string)$row->get('pas_author')))] = true;
        }
        return $set;
    }

    /** Whether this author is on the allow list (case-insensitive). */
    public static function is_allowed(int $owner_user_id, string $persona, string $author): bool {
        return self::find($owner_user_id, $persona, $author, true) !== null;
    }

    /**
     * Allow an author: any live block on them is lifted, and an allow row is
     * created or revived. Idempotent.
     */
    public static function allow(int $owner_user_id, string $persona, string $author, string $note = ''): void {
        $author = trim($author);
        if ($author === '') return;

        PersonaBlockedSender::unblock($owner_user_id, $persona, $author);

        $row = self::find($owner_user_id, $persona, $author, false);
        if ($row !== null) {
            if ($row->get('pas_delete_time')) {
                $row->set('pas_note', mb_substr($note, 0, 255));
                $row->undelete();
            }
            return;
        }

        $row = new PersonaAllowedSender(NULL);
        $row->set('pas_owner_user_id', $owner_user_id);
        $row->set('pas_persona', $persona);
        $row->set('pas_author', $author);
        $row->set('pas_note', mb_substr($note, 0, 255));
        $row->save();
    }

    /** Take an author off the allow list. Nothing else changes — they are
     *  simply an ordinary sender again. */
    public static function disallow(int $owner_user_id, string $persona, string $author): void {
        $row = self::find($owner_user_id, $persona, $author, true);
        if ($row !== null) {
            $row->soft_delete();
        }
    }

    /**
     * The row for this author, matched case-insensitively. With $live_only
     * a soft-deleted row does not count; without it the deleted row is
     * returned so a caller can revive it instead of tripping the unique key.
     */
    private static function find(int $owner_user_id, string $persona, string $author, bool $live_only): ?PersonaAllowedSender {
        $wanted = mb_strtolower(trim($author));
        $options = ['owner_user_id' => $owner_user_id, 'persona' => $persona];
        if ($live_only) $options['deleted'] = false;
        $rows = new MultiPersonaAllowedSender($options);
        foreach ($rows as $row) {
            if (mb_strtolower(trim((string)$row->get('pas_author'))) === $wanted) {
                return $row;
            }
        }
        return null;
    }
}

class MultiPersonaAllowedSender extends SystemMultiBase {
    protected static $model_class = 'PersonaAllowedSender';

    protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];
        if (isset($this->options['owner_user_id'])) {
            $filters['pas_owner_user_id'] = [$this->options['owner_user_id'], PDO::PARAM_INT];
        }
        if (isset($this->options['persona'])) {
            $filters['pas_persona'] = [$this->options['persona'], PDO::PARAM_STR];
        }
        return $this->_get_resultsv2('pas_persona_allowed_senders', $filters, $this->order_by, $only_count, $debug);
    }
}
