package com.getjoinery.memberkit

import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import com.getjoinery.android.JoineryApiError

/**
 * State for the conversation inbox: paginated list with mute/unmute/delete.
 * 20/page, matching the web inbox. All writes go through ConversationApi and
 * patch or reload — the server is the single source of truth, shared with the
 * web conversation page (both ride the same actions).
 */
class ConversationListStore(val api: ConversationApi) {
    var phase by mutableStateOf<MemberPhase>(MemberPhase.Loading)
        private set
    var conversations by mutableStateOf<List<ConversationRow>>(emptyList())
        private set
    var isLoadingMore by mutableStateOf(false)
        private set

    private var totalCount = 0
    private var loadGeneration = 0
    val hasMore: Boolean get() = conversations.size < totalCount

    suspend fun initialLoad() {
        phase = MemberPhase.Loading
        reload()
    }

    suspend fun reload() {
        loadGeneration += 1
        val generation = loadGeneration
        try {
            val page = api.list(0)
            if (generation != loadGeneration) return
            conversations = page.conversations
            totalCount = page.totalCount
            phase = MemberPhase.Loaded
        } catch (e: Exception) {
            if (generation != loadGeneration) return
            if (phase is MemberPhase.Loaded) return
            phase = MemberPhase.Failed(displayMessage(e))
        }
    }

    suspend fun loadMore() {
        if (!hasMore || isLoadingMore) return
        isLoadingMore = true
        val generation = loadGeneration
        try {
            val page = api.list(conversations.size)
            if (generation != loadGeneration) return
            val known = conversations.map { it.conversationId }.toHashSet()
            conversations = conversations + page.conversations.filter { !known.contains(it.conversationId) }
            totalCount = page.totalCount
        } catch (e: Exception) {
            // Paging failures are silent; the next scroll retries.
        } finally {
            isLoadingMore = false
        }
    }

    suspend fun toggleMute(conversation: ConversationRow) {
        val newMuted = !conversation.muted
        patch(conversation.conversationId) { it.copy(muted = newMuted) }
        try {
            api.action(
                if (newMuted) ConversationApi.Mutation.MUTE else ConversationApi.Mutation.UNMUTE,
                conversation.conversationId,
            )
        } catch (e: Exception) {
            reload()
        }
    }

    suspend fun delete(conversation: ConversationRow) {
        conversations = conversations.filter { it.conversationId != conversation.conversationId }
        try {
            api.action(ConversationApi.Mutation.DELETE, conversation.conversationId)
        } catch (e: Exception) {
            reload()
        }
    }

    private fun patch(conversationId: Int, mutate: (ConversationRow) -> ConversationRow) {
        conversations = conversations.map { if (it.conversationId == conversationId) mutate(it) else it }
    }
}

/** How a thread screen was opened: an existing conversation, or `to` a
 *  recipient for compose-mode dedup (the server returns the existing 1:1
 *  conversation if there is one, else an empty compose-mode payload). */
sealed class ThreadOrigin {
    data class Conversation(val id: Int, val otherDisplayName: String) : ThreadOrigin()
    data class Compose(val to: Int, val otherDisplayName: String) : ThreadOrigin()
}

/**
 * State for one conversation: its messages and the compose bar. The server
 * returns messages oldest-first from the start of the thread (no cursor);
 * [loadMore] pages forward with an `after` cursor when scrolled to the bottom,
 * matching the read action's ordering (logic/conversation_thread_logic.php).
 * All writes go through ConversationApi and reconcile against the server.
 */
class ConversationThreadStore(
    val api: ConversationApi,
    private val origin: ThreadOrigin,
) {
    var phase by mutableStateOf<MemberPhase>(MemberPhase.Loading)
        private set
    var messages by mutableStateOf<List<ThreadMessage>>(emptyList())
        private set
    var conversationId by mutableStateOf<Int?>(null)
        private set
    var otherDisplayName by mutableStateOf("")
        private set
    var isMuted by mutableStateOf(false)
        private set
    var hasMore by mutableStateOf(false)
        private set
    var isLoadingMore by mutableStateOf(false)
        private set
    var composerText by mutableStateOf("")
    var isSending by mutableStateOf(false)
        private set
    var sendError by mutableStateOf<String?>(null)
        private set

    init {
        when (origin) {
            is ThreadOrigin.Conversation -> {
                conversationId = origin.id
                otherDisplayName = origin.otherDisplayName
            }
            is ThreadOrigin.Compose -> {
                conversationId = null
                otherDisplayName = origin.otherDisplayName
            }
        }
    }

    val canSend: Boolean get() = composerText.trim().isNotEmpty() && !isSending

    suspend fun load() {
        phase = MemberPhase.Loading
        try {
            apply(fetch())
            phase = MemberPhase.Loaded
        } catch (e: Exception) {
            phase = MemberPhase.Failed(displayMessage(e))
        }
    }

    suspend fun loadMore() {
        val cursor = messages.lastOrNull()?.time
        if (!hasMore || isLoadingMore || cursor == null) return
        isLoadingMore = true
        try {
            val payload = api.thread(conversationId = conversationId, to = null, after = cursor)
            val known = messages.map { it.messageId }.toHashSet()
            messages = messages + payload.messages.filter { !known.contains(it.messageId) }
            hasMore = payload.hasMore
        } catch (e: Exception) {
            // Paging failures are silent; the next scroll retries.
        } finally {
            isLoadingMore = false
        }
    }

    suspend fun send() {
        val text = composerText.trim()
        if (text.isEmpty() || isSending) return
        isSending = true
        composerText = ""
        sendError = null
        try {
            val recipientId = if (conversationId == null) composeRecipient() else null
            val sent = api.send(conversationId = conversationId, to = recipientId, body = text)
            if (conversationId == null) conversationId = sent.conversationId
            messages = messages + ThreadMessage(
                messageId = sent.messageId, senderId = 0, body = sent.body, time = sent.sentTime, isMine = true,
            )
        } catch (e: Exception) {
            composerText = text
            sendError = (e as? JoineryApiError)?.displayMessage ?: "Could not send your message."
        } finally {
            isSending = false
        }
    }

    fun clearSendError() {
        sendError = null
    }

    suspend fun setMuted(muted: Boolean) {
        val id = conversationId ?: return
        isMuted = muted
        try {
            api.action(if (muted) ConversationApi.Mutation.MUTE else ConversationApi.Mutation.UNMUTE, id)
        } catch (e: Exception) {
            isMuted = !muted
        }
    }

    /** Delete the conversation; the caller (thread screen) dismisses on
     *  success. Throws on failure so the caller can surface it. */
    suspend fun delete() {
        val id = conversationId ?: return
        api.action(ConversationApi.Mutation.DELETE, id)
    }

    private fun composeRecipient(): Int? =
        (origin as? ThreadOrigin.Compose)?.to

    private suspend fun fetch(): ThreadPayload {
        conversationId?.let { return api.thread(conversationId = it, to = null) }
        (origin as? ThreadOrigin.Compose)?.let { return api.thread(conversationId = null, to = it.to) }
        throw com.getjoinery.android.JoineryApiError.Malformed
    }

    private fun apply(payload: ThreadPayload) {
        payload.conversationId?.let { conversationId = it }
        if (payload.otherDisplayName.isNotEmpty()) otherDisplayName = payload.otherDisplayName
        isMuted = payload.isMuted
        messages = payload.messages
        hasMore = payload.hasMore
    }
}
