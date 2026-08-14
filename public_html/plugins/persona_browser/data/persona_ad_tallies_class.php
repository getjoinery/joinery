<?php

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));

class PersonaAdTallyException extends SystemBaseException {}

/**
 * Running count of how many of one author's posts the AI has judged to be
 * advertisements — the evidence behind auto-blocking repeat advertisers.
 * Kept separately from the posts because post retention permanently deletes
 * old posts: the tally is what lets a slow-drip advertiser (one ad a week
 * against a 7-day retention window) still accumulate toward the threshold.
 * pat_author_key is the lowercased author name, since captures vary casing;
 * pat_author keeps the casing last seen, for display.
 */
class PersonaAdTally extends SystemBase {
    public static $prefix = 'pat';
    public static $tablename = 'pat_persona_ad_tallies';
    public static $pkey_column = 'pat_persona_ad_tally_id';

    public static $field_specifications = array(
        'pat_persona_ad_tally_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true, 'is_primary_key'=>true),
        'pat_owner_user_id' => array('type'=>'int4', 'is_nullable'=>false, 'default'=>'0', 'unique_with'=>array('pat_persona', 'pat_author_key')),
        'pat_persona' => array('type'=>'varchar(32)', 'is_nullable'=>false, 'required'=>true),
        'pat_author_key' => array('type'=>'varchar(255)', 'is_nullable'=>false, 'required'=>true),
        'pat_author' => array('type'=>'varchar(255)'),
        'pat_ad_count' => array('type'=>'int4', 'is_nullable'=>false, 'default'=>'0'),
        'pat_last_ad_time' => array('type'=>'timestamp(6)', 'is_nullable'=>true),
        'pat_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
        'pat_update_time' => array('type'=>'timestamp(6)', 'is_nullable'=>true),
        'pat_delete_time' => array('type'=>'timestamp(6)', 'is_nullable'=>true),
    );

    /**
     * Count one more judged-ad post for this author and return the lifetime
     * total. Called once per post, at verdict time — the recipe item log
     * guarantees a post is judged only once, so the tally never double-counts.
     */
    public static function record_ad(int $owner_user_id, string $persona, string $author): int {
        $author = trim($author);
        $key = mb_substr(mb_strtolower($author), 0, 255);
        if ($key === '') return 0;

        $existing = new MultiPersonaAdTally([
            'owner_user_id' => $owner_user_id,
            'persona'       => $persona,
            'author_key'    => $key,
        ]);
        $row = null;
        foreach ($existing as $r) { $row = $r; break; }

        if (!$row) {
            $row = new PersonaAdTally(NULL);
            $row->set('pat_owner_user_id', $owner_user_id);
            $row->set('pat_persona', $persona);
            $row->set('pat_author_key', $key);
        }
        $row->set('pat_author', mb_substr($author, 0, 255));
        $row->set('pat_ad_count', (int)$row->get('pat_ad_count') + 1);
        $row->set('pat_last_ad_time', gmdate('Y-m-d H:i:s'));
        $row->save();
        return (int)$row->get('pat_ad_count');
    }
}

class MultiPersonaAdTally extends SystemMultiBase {
    protected static $model_class = 'PersonaAdTally';

    protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];
        if (isset($this->options['owner_user_id'])) {
            $filters['pat_owner_user_id'] = [$this->options['owner_user_id'], PDO::PARAM_INT];
        }
        if (isset($this->options['persona'])) {
            $filters['pat_persona'] = [$this->options['persona'], PDO::PARAM_STR];
        }
        if (isset($this->options['author_key'])) {
            $filters['pat_author_key'] = [$this->options['author_key'], PDO::PARAM_STR];
        }
        return $this->_get_resultsv2('pat_persona_ad_tallies', $filters, $this->order_by, $only_count, $debug);
    }
}
