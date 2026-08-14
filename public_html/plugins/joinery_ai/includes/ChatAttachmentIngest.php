<?php
require_once(PathHelper::getIncludePath('data/files_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversations_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_message_attachments_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/AiAttachment.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatControls.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatRunner.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatSeal.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversation_messages_class.php'));
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
            // Office and OpenDocument files are zips, and libmagic does not always
            // say which kind — resolve those by name so a correctly-named document
            // is not rejected as a bare container. Detection still wins wherever
            // it recognized the format, and the fallback cannot reach a category
            // that sends raw bytes to the model.
            $mime = AiAttachment::resolveUploadMime($mime, $u['name']);
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
            // A document has no native door at all — no model reads a docx or a
            // spreadsheet directly — so one with no readable text has nothing to
            // send. Refuse it here rather than store an attachment the model will
            // only be told about.
            if ($category === 'document' && $extract['status'] !== AiAttachment::EXTRACT_OK) {
                return ['ok' => false, 'error' => '“' . $u['name'] . '” has no readable text — it may be '
                    . 'empty, password-protected, or a scan. Models read these files as text, so there '
                    . 'would be nothing to send.'];
            }

            // The extractor opened the bytes for real, so its verdict outranks
            // both the sniff and the name. A file that turned out to be
            // something else — a plain zip renamed .docx — is refused here even
            // though its name got it through the door.
            $actual = AiAttachment::categoryForCoreCategory($extract['category'] ?? null);
            if ($actual !== null && $actual !== $category) {
                return ['ok' => false, 'error' => '“' . $u['name'] . '” is not the kind of file its name '
                    . 'says it is. Rename it to match what it really is, or attach a different file.'];
            }
            if ($actual === null && ($extract['category'] ?? null) !== null
                    && $extract['status'] === AiAttachment::EXTRACT_OK) {
                return ['ok' => false, 'error' => '“' . $u['name'] . '” is not the kind of file its name '
                    . 'says it is, and its real type can’t be attached here.'];
            }

            // The resolved, validated MIME is the single type authority from here
            // on: it is what commit() hands to storage and what the send side must
            // agree with. client_type is kept only for reference/logging.
            $prepared[] = [
                'bytes'       => $bytes,
                'name'        => $u['name'],
                'client_type' => (string)$u['client_type'],
                'mime'        => $mime,
                'category'    => $category,
                'extract'     => $extract,
            ];
        }
        return ['ok' => true, 'prepared' => $prepared];
    }

    /**
     * User-facing warning naming attachments that were accepted at upload but
     * could not be stored/sent (a server-side type error caught at commit). Both
     * send surfaces attach this to their response so the failure is visible rather
     * than discovered from the model's reply. Empty string when nothing failed.
     */
    public static function failureWarning(array $names): string {
        $names = array_values(array_filter(array_map('strval', $names), function ($n) { return $n !== ''; }));
        if (empty($names)) return '';
        $list = implode(', ', $names);
        if (count($names) === 1) {
            return 'Couldn’t send “' . $list . '” to the model — a server-side type error. Please retry.';
        }
        return 'Couldn’t send these attachments to the model — a server-side type error: ' . $list . '. Please retry.';
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
     * attachment-link row to $message_id owned by $uid.
     *
     * Enforces "accepted means sendable": the type the File persists must route to
     * the same category prepare() validated. A file that fails to store, or whose
     * stored type drifts from the validated one, is dropped (row permanently
     * removed) and reported — never left as a half-stored attachment the send side
     * silently omits.
     *
     * On a protected conversation the extracted text AND the stored bytes are
     * sealed under the OWNING message's DEK (docs/sealed_vault.md) once the link
     * row's id exists (the attachment AD binds to it): the File is written, then
     * its bytes are re-written as ciphertext and fil_type restored (createFromBytes
     * detects type from the on-disk bytes, which are then ciphertext) — the same
     * fil_type-restore mail's sealed attachments use. $message is the (already
     * sealed) user message; $conversation carries the level.
     *
     * @return string[] display names that could NOT be stored (empty = all stored)
     */
    public static function commit(array $prepared, AiConversationMessage $message,
            AiConversation $conversation, int $uid): array {
        $message_id = (int)$message->key;
        $protected  = $conversation->isProtected();
        $failures = [];
        foreach ($prepared as $p) {
            $label = (string)$p['name'];
            try {
                $file = File::createFromBytes($p['bytes'], $p['name'], $p['mime'], $uid, [
                    'fil_private' => true,
                    'fil_source'  => File::SOURCE_AI_CHAT_UPLOAD,
                ]);
            } catch (Throwable $e) {
                error_log('[joinery_ai chat] attachment store failed: ' . $e->getMessage());
                $failures[] = $label;
                continue;
            }

            // createFromBytes detects the type from the bytes again, and for an
            // Office or OpenDocument file that lands on the container it is built
            // from (application/zip) — which the drift guard below would read as a
            // mismatch and drop a perfectly good upload. Persist the resolved type
            // instead, but only on the evidence that settles it: detection landed
            // on a bare container, the name claimed a real format, and the
            // extractor then OPENED the bytes and agreed. Anything less keeps the
            // detected type, so the guard still catches real drift.
            $stored_mime = AiAttachment::normalizeMime($file->get('fil_type'));
            $validated   = $p['category'] ?? null;
            if ($stored_mime !== $p['mime']
                    && in_array($stored_mime, AiAttachment::CONTAINER_MIMES, true)
                    && AiAttachment::categoryForMime($p['mime']) === $validated
                    && AiAttachment::categoryForCoreCategory($p['extract']['category'] ?? null) === $validated) {
                $file->set('fil_type', $p['mime']);
                $file->save();
            }

            // Invariant: the persisted fil_type must map to the category prepare()
            // validated. A mismatch is cross-layer type drift (finfo re-detecting
            // differently at save time than at ingress) — the exact bug class this
            // two-phase design exists to prevent. Fail loud here: drop the row
            // rather than let the send side degrade it to an "omitted" note later.
            $stored_category = AiAttachment::categoryForMime($file->get('fil_type'));
            if ($stored_category === null || $stored_category !== ($p['category'] ?? null)) {
                error_log('[joinery_ai chat] attachment type drift for "' . $label . '": validated '
                    . var_export($p['category'] ?? null, true) . ' but stored fil_type='
                    . var_export($file->get('fil_type'), true) . ' (file ' . $file->key . ') — dropping');
                $file->permanent_delete();
                $failures[] = $label;
                continue;
            }

            // Extraction already ran once in prepare() on the same bytes; reuse it.
            $extract = $p['extract'] ?? ['status' => AiAttachment::EXTRACT_SKIPPED, 'text' => ''];

            $link = new AiMessageAttachment(NULL);
            $link->set('aia_aim_message_id', $message_id);
            $link->set('aia_fil_file_id', (int)$file->key);
            $link->set('aia_extracted_text', (string)$extract['text']);
            $link->set('aia_extract_status', $extract['status']);
            $link->prepare();
            $link->save();
            $link->load();

            if ($protected) {
                try {
                    $sealed = ChatSeal::sealAttachmentUnderMessage($message, (int)$link->key,
                        (string)$extract['text'] !== '' ? (string)$extract['text'] : null, $p['bytes']);
                    // Re-write the on-disk bytes as ciphertext (they were written
                    // plaintext by createFromBytes an instant ago; the File is
                    // fil_private and this all runs inside the one send request),
                    // then restore the real content-type the detector lost.
                    // replace_bytes() splits a dedup-shared blob first so an
                    // identical sibling attachment is never overwritten.
                    if ($sealed['bytes'] !== null) {
                        if ($file->replace_bytes($sealed['bytes'])) {
                            $file->set('fil_type', substr((string)$p['mime'], 0, 128));
                            $file->save();
                        }
                    }
                    // Targeted UPDATE — a full save() would decrypt the now-sealed
                    // aia_extracted_text via get() and write plaintext back.
                    AiMessageAttachment::updateColumns((int)$link->key, [
                        'aia_extracted_text' => $sealed['text'],
                        'aia_sealed'         => true,
                    ]);
                } catch (Throwable $e) {
                    // Sealing failed (locked mid-ingest / vault gone) — drop the
                    // attachment rather than store its content unsealed on a
                    // protected chat. The send still proceeds without this file.
                    error_log('[joinery_ai chat] attachment seal failed for "' . $label . '": ' . $e->getMessage());
                    try { $link->permanent_delete(); } catch (Throwable $ignore) {}
                    $failures[] = $label;
                    continue;
                }
            }
        }
        return $failures;
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
