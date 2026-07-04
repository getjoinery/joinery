<?php
require_once(PathHelper::getIncludePath('data/files_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversations_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_message_attachments_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/AiAttachment.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatControls.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatRunner.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/llm/LlmProviderFactory.php'));

/**
 * Composer-side attachment ingest, shared by BOTH chat_send surfaces — the web
 * page's views/admin/chat_send.php and the /api/v1 chat_send_logic action — so
 * uploads validate and store identically whichever one the request hit. One
 * place owns the $_FILES normalization, the type/size/capability policy, and the
 * File + link-row storage; a change here reaches both surfaces at once.
 *
 * Two phases so a rejected upload never leaves a dangling message or File row:
 *   prepare()  — collect $_FILES, resolve the model + mode, validate every file
 *                (no side effects); returns validated bytes or a rejection.
 *   commit()   — after the user message exists, store each file as a private
 *                File and write its attachment-link row (with extracted text).
 */
class ChatAttachmentIngest {

    /**
     * Collect + validate the multipart `attachments` for a send against the
     * conversation's model + attachment mode. No File rows are minted here.
     * Returns:
     *   ['ok'=>true,  'prepared'=>[ ['bytes','name','client_type'], … ]]
     *   ['ok'=>false, 'error'=>'user-facing message']
     * An absent upload field is a clean empty success.
     */
    public static function prepare(AiConversation $conversation): array {
        $uploads = self::collectUploads($err);
        if ($err !== null) return ['ok' => false, 'error' => $err];
        if (empty($uploads)) return ['ok' => true, 'prepared' => []];

        if (count($uploads) > AiAttachment::maxPerMessage()) {
            return ['ok' => false, 'error' => 'Too many attachments — up to '
                . AiAttachment::maxPerMessage() . ' files per message.'];
        }

        $model = (string)$conversation->get('aic_model');
        if ($model === '') $model = ChatRunner::defaultModel();
        $mode  = (string)$conversation->get('aic_attachment_mode') ?: AiAttachment::MODE_EXTRACT;
        $caps  = LlmProviderFactory::capabilitiesForModel($model);

        $prepared = [];
        foreach ($uploads as $u) {
            $bytes = @file_get_contents($u['tmp']);
            if ($bytes === false || $bytes === '') {
                return ['ok' => false, 'error' => 'Could not read the uploaded file “' . $u['name'] . '”.'];
            }
            $mime = File::detect_mime_bytes($bytes);
            if ($mime === null || $mime === '') $mime = (string)$u['client_type'];
            $reject = AiAttachment::validateRaw($mime, strlen($bytes), $mode, $caps, $u['name']);
            if ($reject !== null) return ['ok' => false, 'error' => $reject];

            // Extract now — before persisting — so we know the real outcome and can
            // fail loud instead of storing an attachment the model will never see.
            // A PDF the current model can't read natively is only sendable if it
            // yields text; a secured or image-only PDF (no text) on such a model has
            // no door, so reject it here rather than degrade to a misleading note.
            $extract  = AiAttachment::extractPath($u['tmp'], $mime);
            $category = AiAttachment::categoryForMime($mime);
            if ($category === 'pdf' && empty($caps['document'])
                    && $extract['status'] !== AiAttachment::EXTRACT_OK) {
                return ['ok' => false, 'error' => '“' . $u['name'] . '” is a secured or image-only PDF '
                    . 'with no readable text, and the selected model can’t read PDFs directly. Switch to '
                    . 'a Claude model, or upload a text-based file.'];
            }

            $prepared[] = [
                'bytes'       => $bytes,
                'name'        => $u['name'],
                'client_type' => (string)$u['client_type'],
                'extract'     => $extract,
            ];
        }
        return ['ok' => true, 'prepared' => $prepared];
    }

    /**
     * Whether this send carries at least one uploaded file — so the caller can
     * allow an empty message body when files are attached. Cheap: inspects
     * $_FILES only.
     */
    public static function hasUploads(): bool {
        $u = self::collectUploads($err);
        return $err === null && !empty($u);
    }

    /**
     * Store each validated upload as a private File (fil_source = ai_chat_upload),
     * extract its text once in the isolated subprocess, and write an
     * attachment-link row to $message_id owned by $uid. Best-effort per file: a
     * single storage failure is logged and skipped, not fatal to the turn.
     */
    public static function commit(array $prepared, int $message_id, int $uid): void {
        foreach ($prepared as $p) {
            try {
                $file = File::createFromBytes($p['bytes'], $p['name'], $p['client_type'], $uid, [
                    'fil_private' => true,
                    'fil_source'  => File::SOURCE_AI_CHAT_UPLOAD,
                ]);
            } catch (Throwable $e) {
                error_log('[joinery_ai chat] attachment store failed: ' . $e->getMessage());
                continue;
            }
            // Extraction already ran once in prepare() on the same bytes; reuse it.
            $extract = $p['extract'] ?? ['status' => AiAttachment::EXTRACT_SKIPPED, 'text' => ''];

            $link = new AiMessageAttachment(NULL);
            $link->set('aia_aim_message_id', $message_id);
            $link->set('aia_fil_file_id', (int)$file->key);
            $link->set('aia_extracted_text', $extract['text']);
            $link->set('aia_extract_status', $extract['status']);
            $link->prepare();
            $link->save();
        }
    }

    /**
     * Normalize the multipart `attachments` upload(s) into a flat list of
     * ['tmp','name','size','client_type'], tolerating both a single file and the
     * array (`attachments[]`) shape. Sets $err to a user-facing message on any
     * per-file PHP upload error (and returns []); otherwise leaves $err null.
     */
    public static function collectUploads(&$err): array {
        $err = null;
        if (empty($_FILES) || !isset($_FILES['attachments'])) return [];

        $f = $_FILES['attachments'];
        $out = [];

        if (is_array($f['name'])) {
            $count = count($f['name']);
            for ($i = 0; $i < $count; $i++) {
                $code = (int)($f['error'][$i] ?? UPLOAD_ERR_NO_FILE);
                if ($code === UPLOAD_ERR_NO_FILE) continue;
                if ($code !== UPLOAD_ERR_OK) {
                    $err = self::uploadErrorMessage((string)($f['name'][$i] ?? 'file'), $code);
                    return [];
                }
                $tmp = (string)($f['tmp_name'][$i] ?? '');
                if ($tmp === '' || !is_uploaded_file($tmp)) {
                    $err = 'Upload failed for “' . (string)($f['name'][$i] ?? 'file') . '”.';
                    return [];
                }
                $out[] = [
                    'tmp'         => $tmp,
                    'name'        => (string)($f['name'][$i] ?? 'attachment'),
                    'size'        => (int)($f['size'][$i] ?? 0),
                    'client_type' => (string)($f['type'][$i] ?? ''),
                ];
            }
            return $out;
        }

        $code = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($code === UPLOAD_ERR_NO_FILE) return [];
        if ($code !== UPLOAD_ERR_OK) {
            $err = self::uploadErrorMessage((string)($f['name'] ?? 'file'), $code);
            return [];
        }
        $tmp = (string)($f['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            $err = 'Upload failed for “' . (string)($f['name'] ?? 'file') . '”.';
            return [];
        }
        $out[] = [
            'tmp'         => $tmp,
            'name'        => (string)($f['name'] ?? 'attachment'),
            'size'        => (int)($f['size'] ?? 0),
            'client_type' => (string)($f['type'] ?? ''),
        ];
        return $out;
    }

    /** User-facing message for a PHP upload error code. */
    private static function uploadErrorMessage(string $name, int $code): string {
        switch ($code) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return 'The file “' . $name . '” is larger than the server upload limit.';
            case UPLOAD_ERR_PARTIAL:
                return 'The file “' . $name . '” was only partially uploaded — please retry.';
            default:
                return 'Upload failed for “' . $name . '” (error ' . $code . ').';
        }
    }
}
