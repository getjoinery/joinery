package com.getjoinery.aichat

import com.getjoinery.android.JsonValue
import java.util.UUID

/** Where a turn is in its lifecycle, mirroring the server's aim_status. */
enum class ChatStatus(val wire: String) {
    RUNNING("running"),
    COMPLETE("complete"),
    FAILED("failed");

    companion object {
        fun from(raw: String?): ChatStatus =
            entries.firstOrNull { it.wire == raw } ?: COMPLETE
    }
}

enum class ChatRole(val wire: String) {
    USER("user"),
    ASSISTANT("assistant");

    companion object {
        fun from(raw: String?): ChatRole? = entries.firstOrNull { it.wire == raw }
    }
}

/** A conversation as it appears in the list (id/title/pinned) and, when
 *  loaded on its own, the extra header fields (model, running usage label). */
data class ChatConversation(
    val id: Int,
    val title: String,
    val pinned: Boolean,
    val model: String? = null,
    val usageLabel: String? = null,
    /** Present only on a full thread load (`chat_thread`), not list rows. */
    val controls: ChatControlValues? = null,
) {
    companion object {
        fun from(data: JsonValue?): ChatConversation? {
            val id = data?.get("id")?.intValue ?: return null
            return ChatConversation(
                id = id,
                title = data["title"]?.stringValue ?: "Untitled",
                pinned = data["pinned"]?.boolValue ?: false,
                model = data["model"]?.takeUnless { it.isNull }?.stringValue,
                usageLabel = data["usage_label"]?.takeUnless { it.isNull }?.stringValue,
                controls = data["controls"]?.let { ChatControlValues.from(it) },
            )
        }
    }
}

/** The per-chat control values. Numeric fields are text ("" = inherit the
 *  plugin-setting default); the picker/toggle fields carry concrete values. */
data class ChatControlValues(
    val model: String = "",
    val dataAccess: Boolean = false,
    val webSearch: Boolean = false,
    val thinkingLevel: String = "off",   // off | low | medium | high
    val temperature: String = "",
    val topP: String = "",
    val maxTokens: String = "",
    val instructions: String = "",
) {
    /** The seed fields sent on a new chat's first message (string-valued;
     *  `ChatControls::seedNewConversation` validates each). */
    fun seedFields(): Map<String, String> {
        val fields = LinkedHashMap<String, String>()
        fields["data_access"] = if (dataAccess) "1" else "0"
        fields["web_search"] = if (webSearch) "1" else "0"
        fields["thinking_level"] = thinkingLevel
        if (model.isNotEmpty()) fields["model"] = model
        if (temperature.isNotEmpty()) fields["temperature"] = temperature
        if (topP.isNotEmpty()) fields["top_p"] = topP
        if (maxTokens.isNotEmpty()) fields["max_tokens"] = maxTokens
        if (instructions.isNotEmpty()) fields["instructions"] = instructions
        return fields
    }

    companion object {
        fun from(data: JsonValue?): ChatControlValues = ChatControlValues(
            model = data?.get("model")?.stringValue ?: "",
            dataAccess = data?.get("data_access")?.boolValue ?: false,
            webSearch = data?.get("web_search")?.boolValue ?: false,
            thinkingLevel = data?.get("thinking_level")?.stringValue ?: "off",
            temperature = numberString(data?.get("temperature")),
            topP = numberString(data?.get("top_p")),
            maxTokens = numberString(data?.get("max_tokens")),
            instructions = data?.get("instructions")?.stringValue ?: "",
        )

        /** A new chat's starting controls: the server defaults, with data
         *  access on so the native assistant is useful out of the box. */
        fun fromDefaults(defaults: ChatControlDefaults): ChatControlValues = ChatControlValues(
            model = defaults.model,
            dataAccess = true,
            webSearch = defaults.webSearch,
            thinkingLevel = defaults.thinkingLevel,
            temperature = defaults.temperature,
            topP = defaults.topP,
            maxTokens = defaults.maxTokens,
        )

        private fun numberString(value: JsonValue?): String {
            if (value == null || value.isNull) return ""
            val d = value.doubleValue ?: return ""
            return if (d == Math.rint(d)) d.toInt().toString() else d.toString()
        }
    }
}

/** One selectable model in the catalog. */
data class ChatModelOption(
    val id: String,
    val label: String,
    val isPrivate: Boolean,
) {
    companion object {
        fun from(data: JsonValue): ChatModelOption? {
            val id = data["id"]?.stringValue ?: return null
            if (id.isEmpty()) return null
            return ChatModelOption(
                id = id,
                label = data["label"]?.stringValue ?: id,
                isPrivate = data["private"]?.boolValue ?: false,
            )
        }
    }
}

/** Resolved default control values, shown as placeholders / new-chat seeds. */
data class ChatControlDefaults(
    val model: String,
    val thinkingLevel: String,
    val temperature: String,
    val topP: String,
    val maxTokens: String,
    val webSearch: Boolean,
) {
    companion object {
        fun from(data: JsonValue?): ChatControlDefaults = ChatControlDefaults(
            model = data?.get("model")?.stringValue ?: "",
            thinkingLevel = data?.get("thinking_level")?.stringValue ?: "off",
            temperature = data?.get("temperature")?.stringValue ?: "",
            topP = data?.get("top_p")?.stringValue ?: "",
            maxTokens = data?.get("max_tokens")?.stringValue ?: "",
            webSearch = data?.get("web_search")?.boolValue ?: false,
        )
    }
}

/** Chat control metadata: the model catalog plus the defaults. */
data class ChatControlsMeta(
    val models: List<ChatModelOption>,
    val webSearchAvailable: Boolean,
    val defaults: ChatControlDefaults,
) {
    /** The catalog label for a model id, falling back to the id. */
    fun label(modelId: String): String =
        models.firstOrNull { it.id == modelId }?.label ?: modelId

    fun isPrivate(modelId: String): Boolean =
        models.firstOrNull { it.id == modelId }?.isPrivate ?: false

    companion object {
        fun from(data: JsonValue?): ChatControlsMeta? {
            if (data == null) return null
            return ChatControlsMeta(
                models = (data["models"]?.arrayValue ?: emptyList())
                    .mapNotNull { ChatModelOption.from(it) },
                webSearchAvailable = data["web_search_available"]?.boolValue ?: false,
                defaults = ChatControlDefaults.from(data["defaults"]),
            )
        }
    }
}

/** A mutating action the assistant proposed and is holding for approval. Its
 *  presence on a turn is what surfaces the Confirm / Cancel card. */
data class ChatPendingAction(val description: String) {
    companion object {
        fun from(data: JsonValue?): ChatPendingAction? {
            if (data == null || data.isNull) return null
            return ChatPendingAction(data["description"]?.stringValue ?: "Run this action?")
        }
    }
}

/** One entry in a turn's tool trace. */
data class ChatToolCall(
    val name: String,
    val isError: Boolean,
    val durationMs: Int?,
) {
    companion object {
        fun from(data: JsonValue): ChatToolCall = ChatToolCall(
            name = data["name"]?.stringValue ?: "?",
            isError = data["is_error"]?.boolValue ?: false,
            durationMs = data["duration_ms"]?.takeUnless { it.isNull }?.intValue,
        )
    }
}

/** A file attached to a turn, as the server serializes it. [imageUrl] is a
 *  short-lived signed URL for image attachments (empty for pdf/text/file); the
 *  view resolves it against the app's base URL and renders a thumbnail. Others
 *  render as a labeled file chip. */
data class ChatAttachment(
    val id: Int,            // the file id
    val name: String,
    val category: String,   // image | pdf | text | html | file
    val imageUrl: String,
) {
    val isImage: Boolean get() = category == "image" && imageUrl.isNotEmpty()

    companion object {
        fun from(data: JsonValue): ChatAttachment? {
            val id = data["file_id"]?.intValue ?: return null
            return ChatAttachment(
                id = id,
                name = data["name"]?.stringValue ?: "attachment",
                category = data["category"]?.stringValue ?: "file",
                imageUrl = data["image_url"]?.stringValue ?: "",
            )
        }
    }
}

/** A file the user picked to send with a message. Carried as a multipart
 *  part; the server validates type, size, and the model's vision/document
 *  capability and is the sole authority — the client only pre-filters. */
data class ChatOutgoingAttachment(
    val id: String = UUID.randomUUID().toString(),
    val filename: String,
    val mimeType: String,
    val data: ByteArray,
) {
    override fun equals(other: Any?): Boolean = other is ChatOutgoingAttachment && other.id == id
    override fun hashCode(): Int = id.hashCode()
}

/** One turn in a conversation. [content] is raw markdown (assistant) or the
 *  user's text; the view renders it. Copied-with-mutation so the store can
 *  fold streamed partial text and the final swap into the same row. */
data class ChatMessage(
    val id: Int,
    val role: ChatRole,
    val content: String,
    val status: ChatStatus,
    val error: String,
    val createdTime: String,
    val pendingAction: ChatPendingAction?,
    val toolCalls: List<ChatToolCall>,
    val costLabel: String,
    val attachments: List<ChatAttachment>,
    /** The runner's live stage label while the turn runs ("Waiting for
     *  glm-5p2…"); empty once settled or against an older server. */
    val activity: String = "",
    /** Server-computed elapsed seconds for a running turn, so a thread
     *  opened mid-generation shows the true elapsed time. */
    val runningSeconds: Int? = null,
) {
    companion object {
        fun from(data: JsonValue?): ChatMessage? {
            val id = data?.get("id")?.intValue ?: return null
            val role = ChatRole.from(data["role"]?.stringValue) ?: return null
            return ChatMessage(
                id = id,
                role = role,
                content = data["content"]?.stringValue ?: "",
                status = ChatStatus.from(data["status"]?.stringValue),
                error = data["error"]?.stringValue ?: "",
                createdTime = data["created_time"]?.stringValue ?: "",
                pendingAction = ChatPendingAction.from(data["pending_action"]),
                toolCalls = (data["tool_calls"]?.arrayValue ?: emptyList()).map { ChatToolCall.from(it) },
                costLabel = data["usage"]?.get("cost_label")?.stringValue ?: "",
                attachments = (data["attachments"]?.arrayValue ?: emptyList())
                    .mapNotNull { ChatAttachment.from(it) },
                activity = data["activity"]?.stringValue ?: "",
                runningSeconds = data["running_seconds"]?.takeUnless { it.isNull }?.intValue,
            )
        }

        /** The assistant placeholder shown while a detached turn runs; the
         *  poll loop fills its content and finally swaps in the persisted row. */
        fun runningPlaceholder(id: Int): ChatMessage = ChatMessage(
            id = id, role = ChatRole.ASSISTANT, content = "", status = ChatStatus.RUNNING,
            error = "", createdTime = "", pendingAction = null, toolCalls = emptyList(),
            costLabel = "", attachments = emptyList(),
        )

        /** A local-only failed assistant row for an error that never reached
         *  (or came back from) the server; a negative id keeps it distinct. */
        fun localFailure(error: String, id: Int): ChatMessage = ChatMessage(
            id = id, role = ChatRole.ASSISTANT, content = "", status = ChatStatus.FAILED,
            error = error, createdTime = "", pendingAction = null, toolCalls = emptyList(),
            costLabel = "", attachments = emptyList(),
        )
    }
}

/** A loaded conversation: its header plus every turn. */
data class ChatThreadPayload(
    val conversation: ChatConversation,
    val messages: List<ChatMessage>,
) {
    companion object {
        fun from(data: JsonValue?): ChatThreadPayload? {
            val conversation = ChatConversation.from(data?.get("conversation")) ?: return null
            return ChatThreadPayload(
                conversation = conversation,
                messages = (data!!["messages"]?.arrayValue ?: emptyList())
                    .mapNotNull { ChatMessage.from(it) },
            )
        }
    }
}

/** The result of a send or a confirm: the poll handle for the running turn,
 *  plus the user turn (send) and — on the synchronous fallback — the finished
 *  assistant turn. */
data class ChatSendResult(
    val conversationId: Int,
    val messageId: Int,
    val isNew: Boolean,
    val title: String,
    val status: ChatStatus,
    val userMessage: ChatMessage?,
    val assistantMessage: ChatMessage?,
    val usageLabel: String?,
    val error: String?,
    /** Present when a file was dropped server-side at commit (type drift);
     *  shown so a dropped attachment is never silent. */
    val attachmentWarning: String?,
) {
    companion object {
        fun from(data: JsonValue?): ChatSendResult? {
            val messageId = data?.get("message_id")?.intValue ?: return null
            return ChatSendResult(
                conversationId = data["conversation_id"]?.intValue ?: 0,
                messageId = messageId,
                isNew = data["is_new"]?.boolValue ?: false,
                title = data["title"]?.stringValue ?: "",
                status = ChatStatus.from(data["status"]?.stringValue),
                userMessage = ChatMessage.from(data["user_message"]),
                assistantMessage = ChatMessage.from(data["assistant_message"]),
                usageLabel = data["usage_label"]?.takeUnless { it.isNull }?.stringValue,
                error = data["error"]?.takeUnless { it.isNull }?.stringValue,
                attachmentWarning = data["attachment_warning"]?.takeUnless { it.isNull }?.stringValue,
            )
        }
    }
}

/** One poll tick: the current status, streamed partial text while running, or
 *  the finished turn / error once settled. While running it also carries the
 *  runner's live stage label and elapsed seconds
 *  (specs/ai_chat_turn_activity.md); both absent against an older server. */
data class ChatPollResult(
    val status: ChatStatus,
    val partialText: String?,
    val message: ChatMessage?,
    val usageLabel: String?,
    val error: String?,
    val activity: String = "",
    val runningSeconds: Int? = null,
) {
    companion object {
        fun from(data: JsonValue?): ChatPollResult? {
            val status = data?.get("status")?.stringValue ?: return null
            return ChatPollResult(
                status = ChatStatus.from(status),
                partialText = data["partial_text"]?.takeUnless { it.isNull }?.stringValue,
                message = ChatMessage.from(data["message"]),
                usageLabel = data["usage_label"]?.takeUnless { it.isNull }?.stringValue,
                error = data["error"]?.takeUnless { it.isNull }?.stringValue,
                activity = data["activity"]?.stringValue ?: "",
                runningSeconds = data["running_seconds"]?.takeUnless { it.isNull }?.intValue,
            )
        }
    }
}
