package com.getjoinery.memberkit

import com.getjoinery.android.JsonValue

/** One row of `conversation_list`. */
data class ConversationRow(
    val conversationId: Int,
    val otherDisplayName: String,
    val preview: String,
    val lastMessageTime: String?,
    val unread: Boolean,
    val muted: Boolean,
) {
    companion object {
        fun from(json: JsonValue): ConversationRow? {
            val conversationId = json["conversation_id"]?.intValue ?: return null
            return ConversationRow(
                conversationId = conversationId,
                otherDisplayName = json["other_display_name"]?.stringValue ?: "Unknown",
                preview = json["preview"]?.stringValue ?: "",
                lastMessageTime = json["last_message_time"]?.takeUnless { it.isNull }?.stringValue,
                unread = json["unread"]?.boolValue ?: false,
                muted = json["muted"]?.boolValue ?: false,
            )
        }
    }
}

/** The `conversation_list` payload. 20/page, matching the web inbox. */
data class ConversationPage(
    val conversations: List<ConversationRow>,
    val totalCount: Int,
    val offset: Int,
    val perPage: Int,
) {
    companion object {
        const val PER_PAGE = 20

        fun from(data: JsonValue?): ConversationPage? {
            if (data == null) return null
            return ConversationPage(
                conversations = (data["conversations"]?.arrayValue ?: emptyList())
                    .mapNotNull { ConversationRow.from(it) },
                totalCount = data["total_count"]?.intValue ?: 0,
                offset = data["offset"]?.intValue ?: 0,
                perPage = data["per_page"]?.intValue ?: PER_PAGE,
            )
        }
    }
}

/** One message of `conversation_thread`. */
data class ThreadMessage(
    val messageId: Int,
    val senderId: Int,
    val body: String,
    val time: String,
    val isMine: Boolean,
) {
    companion object {
        fun from(json: JsonValue): ThreadMessage? {
            val messageId = json["message_id"]?.intValue ?: return null
            return ThreadMessage(
                messageId = messageId,
                senderId = json["sender_id"]?.intValue ?: 0,
                body = json["body"]?.stringValue ?: "",
                time = json["time"]?.stringValue ?: "",
                isMine = json["is_mine"]?.boolValue ?: false,
            )
        }
    }
}

/**
 * The `conversation_thread` payload. `to` compose-mode dedup surfaces as
 * `isComposeMode` with a null `conversationId` and no messages; the first send
 * creates the conversation and the send result carries its id.
 */
data class ThreadPayload(
    val isComposeMode: Boolean,
    val conversationId: Int?,
    val otherDisplayName: String,
    val otherUserId: Int?,
    val isMuted: Boolean,
    val messages: List<ThreadMessage>,
    val hasMore: Boolean,
) {
    companion object {
        fun from(data: JsonValue?): ThreadPayload? {
            if (data == null) return null
            return ThreadPayload(
                isComposeMode = data["is_compose_mode"]?.boolValue ?: false,
                conversationId = data["conversation_id"]?.takeUnless { it.isNull }?.intValue,
                otherDisplayName = data["other_display_name"]?.stringValue ?: "Unknown",
                otherUserId = data["other_user_id"]?.takeUnless { it.isNull }?.intValue,
                isMuted = data["is_muted"]?.boolValue ?: false,
                messages = (data["messages"]?.arrayValue ?: emptyList()).mapNotNull { ThreadMessage.from(it) },
                hasMore = data["has_more"]?.boolValue ?: false,
            )
        }
    }
}

/** The `conversation_send` payload: the created message. */
data class SentMessage(
    val conversationId: Int,
    val messageId: Int,
    val body: String,
    val sentTime: String,
) {
    companion object {
        fun from(data: JsonValue?): SentMessage? {
            if (data == null) return null
            val messageId = data["message_id"]?.intValue ?: return null
            return SentMessage(
                messageId = messageId,
                conversationId = data["conversation_id"]?.intValue ?: 0,
                body = data["body"]?.stringValue ?: "",
                sentTime = data["sent_time"]?.stringValue ?: "",
            )
        }
    }
}
