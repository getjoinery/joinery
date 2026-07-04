<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeToolInterface.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatTurnContext.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/AiAttachment.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/llm/LlmProviderFactory.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_message_attachments_class.php'));

/**
 * On-demand attachment escalation (chat only). In a chat set to "Full file when
 * needed" (aic_attachment_mode = on_demand) each attachment is sent as cheap
 * extracted text, labeled with a stable ref. When that text isn't enough — a
 * table, chart, form layout, or scanned page the model needs to SEE — the model
 * calls this tool with the ref and gets the full original back in the
 * tool_result (a native PDF `document` block, raw HTML, verbatim text, or the
 * image), framed as untrusted just like the initial send.
 *
 * Resolution is by ref (the File id), never by filename — two files can share a
 * name. The ref is validated against THIS conversation's own in-context
 * attachments and the File's owner is re-checked, so the tool can never reach a
 * file from another chat or another user (Security §5).
 */
class ViewAttachmentTool implements RecipeToolInterface {

    public static function name(): string {
        return 'view_attachment';
    }

    public static function description(): string {
        return 'Return the full, original version of a file the user attached to '
             . 'this chat. Use it when the extracted text you were shown is not '
             . 'enough — a table, chart, form layout, signature, or scanned page '
             . 'you need to actually see. Pass `ref`, the integer shown in the '
             . 'attachment\'s label as "[ref N]". Returns the full document/image '
             . 'for that one attachment. Only attachments in this conversation are '
             . 'addressable; images are already shown in full, so this is only '
             . 'needed for PDFs, HTML, and long text files.';
    }

    public static function inputSchema(): array {
        return [
            'type' => 'object',
            'required' => ['ref'],
            'properties' => [
                'ref' => [
                    'type' => 'integer',
                    'description' => 'The attachment ref — the integer shown in its '
                        . 'label, e.g. 642 for an attachment labeled "[ref 642]".',
                ],
            ],
        ];
    }

    public function execute(array $input, ToolContext $ctx) {
        if (!($ctx instanceof ChatTurnContext)) {
            return self::err('view_attachment is only available in chat.');
        }
        $ref = (int)($input['ref'] ?? 0);
        if ($ref <= 0) {
            return self::err('Provide the numeric ref shown next to the attachment (e.g. 642).');
        }

        $conversation_id = $ctx->conversationId();
        $refs = AiMessageAttachment::conversationRefs($conversation_id);
        $valid = [];
        foreach ($refs as $r) $valid[(int)$r['file_id']] = (string)$r['name'];

        if (!isset($valid[$ref])) {
            return self::err('No attachment with ref ' . $ref . ' in this conversation. '
                . self::availableList($refs));
        }

        // Send-time ownership re-check against the conversation owner (§5): catches
        // a File reassigned/deleted between attach and now, in the sessionless
        // worker where is_viewable() would throw.
        $owner = $ctx->conversationOwnerId();
        $file = new File($ref, true);
        if (!$file->key || !$file->is_owned_by($owner)) {
            return self::err('Attachment ref ' . $ref . ' is no longer available.');
        }

        // Build the FULL original through the one shared encoder in original-mode
        // routing (PDF → native document block, HTML → raw markup, text → verbatim,
        // image → image), framed as untrusted. The tool is only offered to a
        // document-capable model, so the native-PDF door is present.
        $model = $ctx->conversationModel();
        $caps = LlmProviderFactory::capabilitiesForModel($model);
        $blocks = AiAttachment::blocksForAttachment(
            $file, '', AiAttachment::EXTRACT_OK, AiAttachment::MODE_ORIGINAL, $caps, $ctx->untrustedNonce());

        if (empty($blocks)) {
            return self::err('Attachment ref ' . $ref . ' could not be read.');
        }
        return ['content' => $blocks, 'is_error' => false];
    }

    private static function availableList(array $refs): string {
        if (empty($refs)) return 'This conversation has no attachments.';
        $parts = [];
        foreach ($refs as $r) $parts[] = (int)$r['file_id'] . ' → ' . (string)$r['name'];
        return 'Available: ' . implode(', ', $parts) . '.';
    }

    private static function err(string $msg): array {
        return ['content' => $msg, 'is_error' => true];
    }
}
