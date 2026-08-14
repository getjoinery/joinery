<?php

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));

class PersonaStoryException extends SystemBaseException {}

/**
 * One entry in the persona's current Stories tray — a teaser, not the story
 * content: who has an active story, a link to view it on the network, and the
 * cover-frame preview image the tray showed (cached locally alongside post
 * media). Unlike feed posts, stories are ephemeral: the table mirrors the most
 * recent capture's tray, so FetchFeedTask's sync inserts what appeared,
 * refreshes what's still there, and permanently deletes what's gone —
 * a stored story row always represents a story Facebook currently offers.
 *
 * pss_position preserves the tray's own left-to-right order (the network
 * sorts unseen stories first), so the strip renders in the same order.
 */
class PersonaStory extends SystemBase {
    public static $prefix = 'pss';
    public static $tablename = 'pss_persona_stories';
    public static $pkey_column = 'pss_persona_story_id';

    public static $field_specifications = array(
        'pss_persona_story_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true, 'is_primary_key'=>true),
        'pss_owner_user_id' => array('type'=>'int4', 'is_nullable'=>false, 'default'=>'0', 'unique_with'=>array('pss_persona', 'pss_story_key')),
        'pss_persona' => array('type'=>'varchar(32)', 'is_nullable'=>false, 'required'=>true),
        // The story's numeric id from its /stories/{id}/ URL.
        'pss_story_key' => array('type'=>'varchar(64)', 'is_nullable'=>false, 'required'=>true),
        'pss_author' => array('type'=>'varchar(255)'),
        'pss_link' => array('type'=>'text'),
        // Locally-cached filenames (media_cache): the story's cover frame and
        // the author's avatar. Either may be empty if the capture missed it.
        'pss_preview_media' => array('type'=>'varchar(255)'),
        'pss_avatar_media' => array('type'=>'varchar(255)'),
        'pss_position' => array('type'=>'int4', 'is_nullable'=>false, 'default'=>'0'),
        'pss_first_seen_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
        'pss_last_seen_time' => array('type'=>'timestamp(6)', 'is_nullable'=>true),
        'pss_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
        'pss_update_time' => array('type'=>'timestamp(6)', 'is_nullable'=>true),
        'pss_delete_time' => array('type'=>'timestamp(6)', 'is_nullable'=>true),
    );

    /** Cached files this story references (for cleanup when it expires). */
    public function media_files(): array {
        $files = array();
        foreach (array('pss_preview_media', 'pss_avatar_media') as $col) {
            $f = (string)$this->get($col);
            if ($f !== '') $files[] = $f;
        }
        return $files;
    }
}

class MultiPersonaStory extends SystemMultiBase {
    protected static $model_class = 'PersonaStory';

    protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];
        if (isset($this->options['owner_user_id'])) {
            $filters['pss_owner_user_id'] = [$this->options['owner_user_id'], PDO::PARAM_INT];
        }
        if (isset($this->options['persona'])) {
            $filters['pss_persona'] = [$this->options['persona'], PDO::PARAM_STR];
        }
        if (isset($this->options['story_key'])) {
            $filters['pss_story_key'] = [$this->options['story_key'], PDO::PARAM_STR];
        }
        return $this->_get_resultsv2('pss_persona_stories', $filters, $this->order_by, $only_count, $debug);
    }
}
