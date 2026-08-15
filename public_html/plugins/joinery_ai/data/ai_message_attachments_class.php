<?php
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('data/files_class.php'));

/**
 * Links one uploaded File to the chat message it was attached to — the
 * "objects as rows" storage the compactor spec dictates: an upload is its own
 * row, not glued into aim_content, so it renders as its own droppable box and
 * carries its own in-context bit.
 *
 * The bytes live in the File model (fil_source = ai_chat_upload, fil_private);
 * this row holds the association plus the text extracted from the file ONCE at
 * ingress (aia_extracted_text / aia_extract_status), so the send-side history
 * build never re-spawns the extraction subprocess per turn.
 *
 * The FK column follows the {prefix}_{source_prefix}_{entity}_id convention
 * (aia_aim_message_id → aim_conversation_messages, aia_fil_file_id → fil_files)
 * so deletion auto-detects both parents. Not $ai_readable — the chat never
 * queries its own attachment table through a tool.
 */
class AiMessageAttachmentException extends SystemBaseException {}

class AiMessageAttachment extends SystemBase {

    public static $prefix = 'aia';
    public static $tablename = 'aia_message_attachments';
    public static $pkey_column = 'aia_attachment_id';

    /**
     * Deleting the underlying File cascades the link row away.
     *
     * The message → attachment edge (aia_aim_message_id) also auto-registers,
     * but is declared explicitly here for clarity: cleanup on message/
     * conversation delete is handled authoritatively by
     * AiConversationMessage::permanent_delete(), which loads each link and
     * calls permanent_delete() below (removing the File bytes too) before its
     * own row is deleted - so this cascade never finds a row left to act on.
     */
    protected static $foreign_key_actions = [
        'aia_fil_file_id'    => ['action' => 'cascade'],
        'aia_aim_message_id' => ['action' => 'cascade'],
    ];

    public static $field_specifications = array(
        'aia_attachment_id'   => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
        // Indexed: looked up on every message render (displayListForMessage),
        // every history build (ChatRunner::attachmentBlocks), and cascade delete.
        'aia_aim_message_id'  => array('type'=>'int8', 'required'=>true, 'index'=>true),
        'aia_fil_file_id'     => array('type'=>'int8', 'required'=>true, 'index'=>true),
        // Compaction bit: an attachment box the compactor may later drop from the
        // sent context. Default in-context (true) until something decides otherwise.
        'aia_in_context'      => array('type'=>'bool', 'is_nullable'=>false, 'default'=>true),
        // Text extracted from the file once at ingress, and the outcome marker
        // (see AiAttachment::EXTRACT_*). Images/original-mode PDFs carry no text.
        'aia_extracted_text'  => array('type'=>'text'),
        'aia_extract_status'  => array('type'=>'varchar(10)'),
        // Sealed Vault marker (specs/joinery_ai_chat_encryption.md). On a protected
        // conversation the extracted text (aia_extracted_text) AND the uploaded
        // File's bytes seal under the OWNING message's DEK (no per-attachment key).
        // aia_sealed = true marks both sealed; the File decrypt hook and the
        // extracted-text read path resolve the message DEK from aia_aim_message_id.
        'aia_sealed'          => array('type'=>'bool', 'is_nullable'=>false, 'default'=>false),
        'aia_create_time'     => array('type'=>'timestamp(6)', 'default'=>'now()'),
        'aia_delete_time'     => array('type'=>'timestamp(6)'),
    );

    // Sealed Vault generic read hook (docs/sealed_vault.md): aia_extracted_text is
    // decrypted transparently by SystemBase::get() on a sealed attachment.
    public static $sealed_fields = array('aia_extracted_text');

    // Sealed under the OWNING message's DEK, not one of its own — ChatSeal
    // owns that key, so it owns this row's sealing too.
    public static $seal_on_save = false;

    /** This model predates the {prefix}_content_sealed convention. */
    public static function sealFlagColumn() {
        return 'aia_sealed';
    }

    /**
     * Decrypt the extracted text in-window. Sealed under the OWNING message's DEK
     * (resolved via aia_aim_message_id in ChatSeal), so decryption loads that
     * message. Returns untouched for an unsealed attachment or a value without the
     * sealed-blob prefix; throws VaultLockedException when the window is closed.
     */
    protected function decryptSealedField($field, $ciphertext) {
        if (!$this->get('aia_sealed') || !is_string($ciphertext)
                || strpos($ciphertext, 'v1.aead.') !== 0) {
            return $ciphertext;
        }
        require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatSeal.php'));
        return ChatSeal::openAttachmentText($this, $ciphertext);
    }

    function authenticate_write($data) {
        if ($data['current_user_permission'] < 5) {
            throw new SystemAuthenticationError(
                'Joinery AI chat attachments require permission level 5 to write.');
        }
    }

    /**
     * Permanently delete this link AND the File it points at, so no upload bytes
     * are leaked when a message or conversation is deleted. Order matters: remove
     * the File first (it has its own on-disk/cloud cleanup), then the link row.
     * File::permanent_delete() is itself best-effort about the bytes (a cloud
     * delete failure is logged and swallowed inside File, not thrown), so a real
     * exception here means the File's own row could not be deleted (a genuine DB
     * failure) — in that case this method must NOT delete the link row too, or
     * the pair would come apart: an orphaned File with nothing pointing at it.
     * Let the exception propagate so link and File stay paired for a retry.
     */
    // Writes to a sealed attachment go through SystemBase::updateColumns() —
    // never save(), which would decrypt aia_extracted_text via get() and write
    // plaintext back.

    public function permanent_delete($debug = false) {
        $file_id = (int)$this->get('aia_fil_file_id');
        if ($file_id > 0) {
            $file = new File($file_id, true);
            if ($file->key) {
                $file->permanent_delete($debug);
            }
        }
        return parent::permanent_delete($debug);
    }

    /**
     * Display metadata for a message's in-context attachments, for the transcript
     * (HTML bubble and JSON message). Each entry is
     *   ['file_id','name','category','image_url']
     * where category is AiAttachment's routing category (image|pdf|text|html|file)
     * and image_url is a short-lived signed serve URL for images only (empty
     * otherwise) so a cookieless native client can load it directly; a browser
     * loads the same URL unchanged. Cheap and read-only — safe on every render.
     */
    public static function displayListForMessage($message_id): array {
        require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/AiAttachment.php'));
        $message_id = (int)$message_id;
        if ($message_id <= 0) return [];

        $links = new MultiAiMessageAttachment(
            ['message_id' => $message_id, 'in_context' => true, 'deleted' => false],
            ['aia_attachment_id' => 'ASC']
        );
        $links->load();

        $out = [];
        foreach ($links as $link) {
            $file = new File((int)$link->get('aia_fil_file_id'), true);
            if (!$file->key) continue;
            $category = AiAttachment::categoryForMime($file->get('fil_type')) ?: 'file';
            $out[] = [
                'file_id'   => (int)$file->key,
                'name'      => AiAttachment::displayName($file),
                'category'  => $category,
                'image_url' => $category === 'image' ? (string)$file->mintSignedUrl('original', 300) : '',
            ];
        }
        return $out;
    }

    /**
     * The in-context attachments across a whole conversation, as
     * [['file_id'=>int,'name'=>string], …] in attach order. Scopes a
     * view_attachment ref lookup to the conversation the model is in — a ref
     * outside this set is not addressable, so the tool cannot reach a file from
     * another chat. Read-only.
     */
    public static function conversationRefs(int $conversation_id): array {
        require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/AiAttachment.php'));
        if ($conversation_id <= 0) return [];
        $sql = 'SELECT a.aia_fil_file_id AS file_id '
             . 'FROM aia_message_attachments a '
             . 'JOIN aim_conversation_messages m ON m.aim_message_id = a.aia_aim_message_id '
             . 'WHERE m.aim_aic_conversation_id = ? '
             . 'AND a.aia_delete_time IS NULL AND a.aia_in_context IS TRUE '
             . 'AND m.aim_delete_time IS NULL '
             . 'ORDER BY a.aia_attachment_id ASC';
        try {
            $q = DbConnector::get_instance()->get_db_link()->prepare($sql);
            $q->execute([$conversation_id]);
            $ids = $q->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($ids as $fid) {
            $file = new File((int)$fid, true);
            if (!$file->key) continue;
            $out[] = ['file_id' => (int)$file->key, 'name' => AiAttachment::displayName($file)];
        }
        return $out;
    }
}

class MultiAiMessageAttachment extends SystemMultiBase {
    protected static $model_class = 'AiMessageAttachment';

    protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];

        if (isset($this->options['message_id'])) {
            $filters['aia_aim_message_id'] = [$this->options['message_id'], PDO::PARAM_INT];
        }

        if (isset($this->options['file_id'])) {
            $filters['aia_fil_file_id'] = [$this->options['file_id'], PDO::PARAM_INT];
        }

        if (isset($this->options['in_context'])) {
            $filters['aia_in_context'] = $this->options['in_context'] ? "IS TRUE" : "IS FALSE";
        }

        if (isset($this->options['deleted'])) {
            $filters['aia_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
        } else {
            $filters['aia_delete_time'] = "IS NULL";
        }

        return $this->_get_resultsv2('aia_message_attachments', $filters, $this->order_by, $only_count, $debug);
    }
}
