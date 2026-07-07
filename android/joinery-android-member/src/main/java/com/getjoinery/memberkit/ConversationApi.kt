package com.getjoinery.memberkit

import com.getjoinery.android.ApiClient
import com.getjoinery.android.JoineryApiError
import com.getjoinery.android.JsonValue

/**
 * Thin typed face over the `conversation_*` actions. Every call rides the
 * app's session key through ApiClient; participant authorization is entirely
 * server-side, matching the web conversation page's checks (which the same
 * actions back — specs/implemented/mobile_native_member_screens.md).
 */
class ConversationApi(val client: ApiClient) {

    enum class Mutation(val slug: String) {
        MUTE("mute"),
        UNMUTE("unmute"),
        DELETE("delete"),
    }

    suspend fun list(offset: Int): ConversationPage {
        val envelope = client.submitAction(
            "conversation_list",
            JsonValue.obj("offset" to JsonValue.Num(offset.toDouble())),
        )
        return ConversationPage.from(envelope["data"]) ?: throw JoineryApiError.Malformed
    }

    /** Load an existing conversation, or dedup into one for [to] in compose
     *  mode. [before]/[after] are ISO UTC cursors; omit both for the newest
     *  page. Marks the conversation read as a side effect. */
    suspend fun thread(
        conversationId: Int?,
        to: Int?,
        before: String? = null,
        after: String? = null,
    ): ThreadPayload {
        val body = ArrayList<Pair<String, JsonValue>>()
        if (conversationId != null) {
            body.add("conversation_id" to JsonValue.Num(conversationId.toDouble()))
        } else if (to != null) {
            body.add("to" to JsonValue.Num(to.toDouble()))
        }
        if (before != null) body.add("before" to JsonValue.Str(before))
        if (after != null) body.add("after" to JsonValue.Str(after))
        val envelope = client.submitAction("conversation_thread", JsonValue.Obj(body))
        return ThreadPayload.from(envelope["data"]) ?: throw JoineryApiError.Malformed
    }

    /** Send a message. Provide [conversationId] for an existing thread, or [to]
     *  to create/reuse a 1:1 conversation with that user. */
    suspend fun send(conversationId: Int?, to: Int?, body: String): SentMessage {
        val fields = ArrayList<Pair<String, JsonValue>>()
        if (conversationId != null) {
            fields.add("conversation_id" to JsonValue.Num(conversationId.toDouble()))
        } else if (to != null) {
            fields.add("to" to JsonValue.Num(to.toDouble()))
        }
        fields.add("body" to JsonValue.Str(body))
        val envelope = client.submitAction("conversation_send", JsonValue.Obj(fields))
        return SentMessage.from(envelope["data"]) ?: throw JoineryApiError.Malformed
    }

    suspend fun action(mutation: Mutation, conversationId: Int): JsonValue =
        client.submitAction(
            "conversation_action",
            JsonValue.obj(
                "conversation_id" to JsonValue.Num(conversationId.toDouble()),
                "action" to JsonValue.Str(mutation.slug),
            ),
        )
}
