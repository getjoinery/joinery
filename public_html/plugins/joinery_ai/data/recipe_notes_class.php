<?php
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class RecipeNoteException extends SystemBaseException {}

class RecipeNote extends SystemBase {

    public static $prefix = 'rcn';
    public static $tablename = 'rcn_notes';
    public static $pkey_column = 'rcn_note_id';

    public static $field_specifications = array(
        'rcn_note_id'         => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
        'rcn_owner_user_id'   => array('type'=>'int4', 'required'=>true),
        'rcn_title'           => array('type'=>'varchar(255)', 'required'=>true),
        'rcn_content'         => array('type'=>'text'),
        'rcn_tags'            => array('type'=>'jsonb'),
        'rcn_create_time'     => array('type'=>'timestamp(6)', 'default'=>'now()'),
        'rcn_update_time'     => array('type'=>'timestamp(6)'),
        'rcn_delete_time'     => array('type'=>'timestamp(6)'),
    );

    public static $json_vars = array('rcn_tags');

    /**
     * Upsert by (owner, title): if a non-deleted note with the same title
     * exists for this owner, return it loaded. Otherwise return a fresh
     * unsaved note. Caller still has to set fields and call save().
     */
    static function FindOrNewByTitle($owner_user_id, $title) {
        $existing = new MultiRecipeNote(array(
            'owner_user_id' => $owner_user_id,
            'title' => $title,
        ));
        $existing->load();

        if (count($existing)) {
            return $existing->get(0);
        }

        $note = new RecipeNote(NULL);
        $note->set('rcn_owner_user_id', $owner_user_id);
        $note->set('rcn_title', $title);
        return $note;
    }

    function authenticate_write($data) {
        if ($this->get('rcn_owner_user_id') != $data['current_user_id']
            && $data['current_user_permission'] < 10) {
            throw new SystemAuthenticationError(
                'Cannot edit a note owned by another user.');
        }
    }

}

class MultiRecipeNote extends SystemMultiBase {
    protected static $model_class = 'RecipeNote';

    protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];

        if (isset($this->options['owner_user_id'])) {
            $filters['rcn_owner_user_id'] = [$this->options['owner_user_id'], PDO::PARAM_INT];
        }

        if (isset($this->options['title'])) {
            $filters['rcn_title'] = [$this->options['title'], PDO::PARAM_STR];
        }

        if (isset($this->options['deleted'])) {
            $filters['rcn_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
        } else {
            $filters['rcn_delete_time'] = "IS NULL";
        }

        return $this->_get_resultsv2('rcn_notes', $filters, $this->order_by, $only_count, $debug);
    }

}
