package com.getjoinery.aichat

import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import com.getjoinery.android.JoineryApiError
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch

/**
 * State for the conversation list: the caller's chats, with live search and
 * pin/rename/delete. All writes go through [ChatApi] and reload — the server
 * is the single source of truth, shared with the web chat.
 */
class ChatListStore(val api: ChatApi) {
    sealed class Phase {
        object Loading : Phase()
        object Loaded : Phase()
        data class Failed(val message: String) : Phase()
    }

    var phase by mutableStateOf<Phase>(Phase.Loading)
        private set
    var conversations by mutableStateOf<List<ChatConversation>>(emptyList())
        private set
    var searchText by mutableStateOf("")
    var activeQuery by mutableStateOf("")
        private set

    /** Ignores stale in-flight loads after the search term changes. */
    private var loadGeneration = 0

    suspend fun initialLoad() {
        phase = Phase.Loading
        reload()
    }

    suspend fun reload() {
        loadGeneration += 1
        val generation = loadGeneration
        try {
            val list = api.list(activeQuery)
            if (generation != loadGeneration) return
            conversations = list
            phase = Phase.Loaded
        } catch (e: Exception) {
            if (generation != loadGeneration) return
            if (phase is Phase.Loaded) return
            phase = Phase.Failed(displayMessage(e))
        }
    }

    suspend fun submitSearch() {
        activeQuery = searchText.trim()
        reload()
    }

    suspend fun clearSearch() {
        if (activeQuery.isEmpty()) return
        searchText = ""
        activeQuery = ""
        reload()
    }

    // MARK: Row actions

    suspend fun togglePin(conversation: ChatConversation) {
        try {
            api.pin(conversation.id, !conversation.pinned)
        } catch (e: Exception) {
            // fall through to reload either way
        }
        // Reload to pick up the server's pinned-first ordering.
        reload()
    }

    suspend fun rename(conversation: ChatConversation, title: String) {
        val trimmed = title.trim()
        if (trimmed.isEmpty()) return
        try {
            api.rename(conversation.id, trimmed)
            conversations = conversations.map {
                if (it.id == conversation.id) it.copy(title = trimmed) else it
            }
        } catch (e: Exception) {
            reload()
        }
    }

    suspend fun delete(conversation: ChatConversation) {
        conversations = conversations.filter { it.id != conversation.id }
        try {
            api.deleteConversation(conversation.id)
        } catch (e: Exception) {
            reload()
        }
    }

    private fun displayMessage(e: Exception): String =
        (e as? JoineryApiError)?.displayMessage ?: (e.message ?: "Something went wrong.")
}

/**
 * State for one conversation: its turns, the composer, and the poll loop that
 * delivers a running turn's streamed answer. All writes go through [ChatApi]
 * and reconcile against the server; a brand-new chat starts with no id and
 * gets one back from the first send.
 */
class ChatThreadStore(
    val api: ChatApi,
    conversationId: Int?,
    title: String = "New chat",
    private val scope: CoroutineScope,
) {
    sealed class Phase {
        object Loading : Phase()
        object Loaded : Phase()
        data class Failed(val message: String) : Phase()
    }

    companion object {
        /** How often the client asks the server for a running turn's progress
         *  — matches the web reader's cadence. */
        private const val POLL_INTERVAL_MS = 600L

        /** Consecutive poll transport errors tolerated before giving up. */
        private const val POLL_ERROR_TOLERANCE = 5
    }

    // A brand-new chat has nothing to load — it's ready for the first send.
    var phase by mutableStateOf<Phase>(if (conversationId == null) Phase.Loaded else Phase.Loading)
        private set
    var messages by mutableStateOf<List<ChatMessage>>(emptyList())
        private set
    var conversationId by mutableStateOf(conversationId)
        private set
    var title by mutableStateOf(title)
        private set
    var usageLabel by mutableStateOf("")
        private set
    var composerText by mutableStateOf("")
    var isSending by mutableStateOf(false)
        private set

    /** Files the user has picked for the next send, shown as removable chips
     *  in the composer. */
    var pendingAttachments by mutableStateOf<List<ChatOutgoingAttachment>>(emptyList())
        private set

    /** A one-off notice — e.g. a file the server dropped at commit — shown
     *  above the composer until the next send clears it. */
    var attachmentNotice by mutableStateOf("")
        private set

    /** The per-chat controls (model, capabilities, reasoning, sampling)
     *  driving the settings sheet; seeded onto a new chat's first send. */
    var controls by mutableStateOf(ChatControlValues())
        private set

    /** The model catalog + defaults, loaded lazily when settings first opens. */
    var meta by mutableStateOf<ChatControlsMeta?>(null)
        private set

    private var pollJob: Job? = null
    private var localFailureCounter = 0

    /** Origin for resolving the relative signed image URLs the server returns
     *  on attachments. */
    val baseUrl: String get() = api.client.config.baseUrl

    /** True for a new chat whose controls haven't been seeded from the server
     *  defaults yet — the first meta load overwrites them; a later load
     *  leaves any user edits alone. */
    private var controlsNeedDefaults = conversationId == null

    /** True while the newest turn is still being generated. */
    val isTurnRunning: Boolean
        get() = messages.lastOrNull()?.status == ChatStatus.RUNNING

    val canSend: Boolean
        get() = (composerText.trim().isNotEmpty() || pendingAttachments.isNotEmpty()) &&
            !isSending && !isTurnRunning

    /** Queue a picked file for the next send. */
    fun addAttachment(attachment: ChatOutgoingAttachment) {
        pendingAttachments = pendingAttachments + attachment
    }

    /** Drop a queued file before it's sent. */
    fun removeAttachment(id: String) {
        pendingAttachments = pendingAttachments.filter { it.id != id }
    }

    fun dismissAttachmentNotice() {
        attachmentNotice = ""
    }

    fun stopPolling() {
        pollJob?.cancel()
        pollJob = null
    }

    suspend fun load() {
        val id = conversationId
        if (id == null) {
            phase = Phase.Loaded
            return
        }
        phase = Phase.Loading
        try {
            val payload = api.thread(id)
            title = payload.conversation.title
            usageLabel = payload.conversation.usageLabel ?: ""
            payload.conversation.controls?.let { controls = it }
            messages = payload.messages
            phase = Phase.Loaded
            // A turn already in flight (opened mid-generation) keeps streaming.
            val last = messages.lastOrNull()
            if (last != null && last.role == ChatRole.ASSISTANT && last.status == ChatStatus.RUNNING) {
                startPolling(last.id)
            }
        } catch (e: Exception) {
            phase = Phase.Failed(displayMessage(e))
        }
    }

    suspend fun send() {
        val text = composerText.trim()
        val attachments = pendingAttachments
        if ((text.isEmpty() && attachments.isEmpty()) || isSending || isTurnRunning) return
        isSending = true
        composerText = ""
        pendingAttachments = emptyList()
        attachmentNotice = ""
        try {
            val isNew = conversationId == null
            val result = api.send(
                message = text,
                conversationId = conversationId,
                seed = if (isNew) controls.seedFields() else emptyMap(),
                attachments = attachments,
            )
            if (isNew) {
                conversationId = result.conversationId
                title = result.title
                controlsNeedDefaults = false   // controls are now the created chat's
            }
            result.userMessage?.let { messages = messages + it }
            result.attachmentWarning?.takeUnless { it.isEmpty() }?.let { attachmentNotice = it }
            finishOrPoll(result)
        } catch (e: Exception) {
            // Re-queue the picked files so a transient failure doesn't force
            // the user back through the picker.
            pendingAttachments = attachments
            appendLocalFailure(
                (e as? JoineryApiError)?.displayMessage ?: "Could not send your message.",
            )
        } finally {
            isSending = false
        }
    }

    /** Approve or decline a proposed action; the same assistant row resumes. */
    suspend fun resolvePending(messageId: Int, decision: String) {
        val id = conversationId ?: return
        setRunning(messageId)
        try {
            val result = api.confirm(id, messageId, decision)
            finishOrPoll(result, fallbackMessageId = messageId)
        } catch (e: Exception) {
            markFailed(
                messageId,
                (e as? JoineryApiError)?.displayMessage ?: "Could not resolve the action.",
            )
        }
    }

    suspend fun deleteTurn(message: ChatMessage) {
        try {
            val removed = api.deleteTurn(message.id).toSet()
            messages = messages.filter { !removed.contains(it.id) }
        } catch (e: Exception) {
            // Leave the row; a reload will reconcile.
        }
    }

    // MARK: Controls

    /** Fetch the model catalog + defaults (once). For a not-yet-created chat,
     *  seed its controls from the defaults so the sheet shows real values. */
    suspend fun loadMeta() {
        if (meta != null) return
        try {
            val loaded = api.controls()
            meta = loaded
            if (controlsNeedDefaults) {
                controls = ChatControlValues.fromDefaults(loaded.defaults)
                controlsNeedDefaults = false
            }
        } catch (e: Exception) {
            // Leave meta null; the sheet shows a spinner and the caller can retry.
        }
    }

    /** Update one control. Applied locally immediately; on an existing chat
     *  it also persists server-side (a new chat carries it on the first send). */
    fun setControl(field: String, value: String, apply: (ChatControlValues) -> ChatControlValues) {
        controls = apply(controls)
        val id = conversationId ?: return
        scope.launch {
            try {
                api.setControl(id, field, value)
            } catch (e: Exception) {
                // Server rejected or transport failed; the next thread load reconciles.
            }
        }
    }

    // MARK: Turn delivery

    /** A synchronous fallback response carries the finished assistant turn;
     *  otherwise show a placeholder and poll the running row. */
    private fun finishOrPoll(result: ChatSendResult, fallbackMessageId: Int? = null) {
        val assistant = result.assistantMessage
        if (assistant != null) {
            upsert(assistant)
            result.usageLabel?.let { usageLabel = it }
        } else if (result.status == ChatStatus.FAILED) {
            val id = fallbackMessageId ?: result.messageId
            markFailed(
                id, result.error ?: "The assistant could not complete this turn.",
                insertIfMissing = true,
            )
        } else {
            val id = fallbackMessageId ?: result.messageId
            if (messages.none { it.id == id }) {
                messages = messages + ChatMessage.runningPlaceholder(id)
            }
            startPolling(id)
        }
    }

    private fun startPolling(messageId: Int) {
        pollJob?.cancel()
        pollJob = scope.launch {
            var errorStreak = 0
            while (isActive) {
                delay(POLL_INTERVAL_MS)
                if (!isActive) return@launch
                try {
                    val result = api.poll(messageId)
                    errorStreak = 0
                    when (result.status) {
                        ChatStatus.RUNNING -> {
                            result.partialText?.let { updatePartial(messageId, it) }
                            updateActivity(messageId, result.activity, result.runningSeconds)
                        }
                        ChatStatus.COMPLETE -> {
                            result.message?.let { upsert(it) }
                            result.usageLabel?.let { usageLabel = it }
                            return@launch
                        }
                        ChatStatus.FAILED -> {
                            markFailed(
                                messageId,
                                result.error ?: "The assistant could not complete this turn.",
                            )
                            return@launch
                        }
                    }
                } catch (e: Exception) {
                    errorStreak += 1
                    if (errorStreak >= POLL_ERROR_TOLERANCE) {
                        markFailed(
                            messageId,
                            (e as? JoineryApiError)?.displayMessage
                                ?: "Lost connection to the assistant.",
                        )
                        return@launch
                    }
                }
            }
        }
    }

    // MARK: Row mutators

    private fun upsert(message: ChatMessage) {
        messages = if (messages.any { it.id == message.id }) {
            messages.map { if (it.id == message.id) message else it }
        } else {
            messages + message
        }
    }

    private fun updatePartial(id: Int, text: String) {
        messages = messages.map {
            if (it.id == id) it.copy(content = text, status = ChatStatus.RUNNING) else it
        }
    }

    /** Fold a poll tick's live stage label + elapsed time onto the running
     *  row (specs/ai_chat_turn_activity.md). */
    private fun updateActivity(id: Int, activity: String, runningSeconds: Int?) {
        messages = messages.map {
            if (it.id == id) it.copy(activity = activity, runningSeconds = runningSeconds) else it
        }
    }

    private fun setRunning(id: Int) {
        messages = messages.map {
            if (it.id == id) it.copy(status = ChatStatus.RUNNING, pendingAction = null) else it
        }
    }

    private fun markFailed(id: Int, error: String, insertIfMissing: Boolean = false) {
        if (messages.any { it.id == id }) {
            messages = messages.map {
                if (it.id == id) it.copy(status = ChatStatus.FAILED, error = error) else it
            }
        } else if (insertIfMissing) {
            appendLocalFailure(error)
        }
    }

    private fun appendLocalFailure(error: String) {
        localFailureCounter -= 1
        messages = messages + ChatMessage.localFailure(error, id = localFailureCounter)
    }

    private fun displayMessage(e: Exception): String =
        (e as? JoineryApiError)?.displayMessage ?: (e.message ?: "Something went wrong.")
}
