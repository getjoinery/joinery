package com.getjoinery.aichat

import com.getjoinery.android.ApiClient
import com.getjoinery.android.JoineryApiError
import com.getjoinery.android.JsonValue
import com.getjoinery.android.MultipartFile

/**
 * Thin typed face over the `joinery_ai/chat_*` actions (the native chat
 * surface). Every call rides the app's session key through [ApiClient];
 * conversations are owner-scoped entirely server-side. Turns run detached on
 * the server, so `send`/`confirm` return a poll handle and `poll` delivers
 * the streaming result — mirroring the web reader's send-then-poll transport.
 */
class ChatApi(val client: ApiClient) {

    /** The caller's conversations, pinned-first then newest, optional search. */
    suspend fun list(search: String = ""): List<ChatConversation> {
        val body = ArrayList<Pair<String, JsonValue>>()
        if (search.isNotEmpty()) body.add("search" to JsonValue.Str(search))
        val envelope = client.submitAction("joinery_ai/chat_list", JsonValue.Obj(body))
        return (envelope["data"]?.get("conversations")?.arrayValue ?: emptyList())
            .mapNotNull { ChatConversation.from(it) }
    }

    /** One conversation and its turns. */
    suspend fun thread(conversationId: Int): ChatThreadPayload {
        val envelope = client.submitAction(
            "joinery_ai/chat_thread",
            JsonValue.obj("conversation_id" to JsonValue.Num(conversationId.toDouble())),
        )
        return ChatThreadPayload.from(envelope["data"]) ?: throw JoineryApiError.Malformed
    }

    /** Send a message. Omit [conversationId] to start a new conversation, in
     *  which case [seed] carries the control fields the new chat is created
     *  with. When [attachments] is non-empty the call goes out as multipart so
     *  the files reach the server's `$_FILES['attachments']`; otherwise it's a
     *  plain JSON action. */
    suspend fun send(
        message: String,
        conversationId: Int?,
        seed: Map<String, String> = emptyMap(),
        attachments: List<ChatOutgoingAttachment> = emptyList(),
    ): ChatSendResult {
        val envelope: JsonValue
        if (attachments.isEmpty()) {
            val body = ArrayList<Pair<String, JsonValue>>()
            body.add("message" to JsonValue.Str(message))
            if (conversationId != null) {
                body.add("conversation_id" to JsonValue.Num(conversationId.toDouble()))
            } else {
                seed.entries.sortedBy { it.key }.forEach { (key, value) ->
                    body.add(key to JsonValue.Str(value))
                }
            }
            envelope = client.submitAction("joinery_ai/chat_send", JsonValue.Obj(body))
        } else {
            val fields = ArrayList<Pair<String, String>>()
            fields.add("message" to message)
            if (conversationId != null) {
                fields.add("conversation_id" to conversationId.toString())
            } else {
                seed.entries.sortedBy { it.key }.forEach { (key, value) ->
                    fields.add(key to value)
                }
            }
            val files = attachments.map {
                MultipartFile("attachments[]", it.filename, it.mimeType, it.data)
            }
            envelope = client.submitMultipart("joinery_ai/chat_send", fields, files)
        }
        return ChatSendResult.from(envelope["data"]) ?: throw JoineryApiError.Malformed
    }

    /** The model catalog and default control values for the settings sheet. */
    suspend fun controls(): ChatControlsMeta {
        val envelope = client.submitAction("joinery_ai/chat_controls", JsonValue.Obj(emptyList()))
        return ChatControlsMeta.from(envelope["data"]) ?: throw JoineryApiError.Malformed
    }

    /** Set one control on an existing conversation (string-valued; booleans
     *  as "1"/"0"). Validated server-side against ChatControls. */
    suspend fun setControl(conversationId: Int, field: String, value: String) {
        client.submitAction(
            "joinery_ai/chat_set_capabilities",
            JsonValue.obj(
                "conversation_id" to JsonValue.Num(conversationId.toDouble()),
                "field" to JsonValue.Str(field),
                "value" to JsonValue.Str(value),
            ),
        )
    }

    /** One poll tick for a running turn. */
    suspend fun poll(messageId: Int): ChatPollResult {
        val envelope = client.submitAction(
            "joinery_ai/chat_poll",
            JsonValue.obj("message_id" to JsonValue.Num(messageId.toDouble())),
        )
        return ChatPollResult.from(envelope["data"]) ?: throw JoineryApiError.Malformed
    }

    /** Resolve a proposed action (`confirm` | `cancel`); the turn resumes on
     *  the same message id, polled like a send. */
    suspend fun confirm(conversationId: Int, messageId: Int, decision: String): ChatSendResult {
        val envelope = client.submitAction(
            "joinery_ai/chat_confirm",
            JsonValue.obj(
                "conversation_id" to JsonValue.Num(conversationId.toDouble()),
                "message_id" to JsonValue.Num(messageId.toDouble()),
                "decision" to JsonValue.Str(decision),
            ),
        )
        return ChatSendResult.from(envelope["data"]) ?: throw JoineryApiError.Malformed
    }

    /** Delete a turn; returns the ids the server actually removed (a user
     *  turn takes its paired reply with it). */
    suspend fun deleteTurn(messageId: Int): List<Int> {
        val envelope = client.submitAction(
            "joinery_ai/chat_turn_action",
            JsonValue.obj(
                "message_id" to JsonValue.Num(messageId.toDouble()),
                "action" to JsonValue.Str("delete"),
            ),
        )
        return (envelope["data"]?.get("deleted_ids")?.arrayValue ?: emptyList())
            .mapNotNull { it.intValue }
    }

    suspend fun pin(conversationId: Int, pinned: Boolean) =
        threadAction("pin", conversationId, if (pinned) "1" else "0")

    suspend fun rename(conversationId: Int, title: String) =
        threadAction("rename", conversationId, title)

    suspend fun deleteConversation(conversationId: Int) =
        threadAction("delete", conversationId)

    private suspend fun threadAction(action: String, conversationId: Int, value: String? = null) {
        val body = ArrayList<Pair<String, JsonValue>>()
        body.add("conversation_id" to JsonValue.Num(conversationId.toDouble()))
        body.add("action" to JsonValue.Str(action))
        if (value != null) body.add("value" to JsonValue.Str(value))
        client.submitAction("joinery_ai/chat_thread_action", JsonValue.Obj(body))
    }
}
