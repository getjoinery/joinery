package com.getjoinery.mail

import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import com.getjoinery.android.JoineryApiError
import kotlinx.coroutines.async
import kotlinx.coroutines.coroutineScope

/**
 * State for the mailbox screen: the granted mailboxes plus the thread list
 * for the current mailbox / view / folder / search, with paging. All
 * mutations go through [MailApi] and re-read or locally patch the list — the
 * server is the single source of truth shared with the web reader.
 */
class MailboxStore(val api: MailApi) {
    sealed class Phase {
        object Loading : Phase()
        object Loaded : Phase()
        data class Failed(val message: String) : Phase()
    }

    var phase by mutableStateOf<Phase>(Phase.Loading)
        private set
    var home by mutableStateOf<MailboxHome?>(null)
        private set
    var threads by mutableStateOf<List<ThreadSummary>>(emptyList())
        private set
    var hasMore by mutableStateOf(false)
        private set
    var isLoadingMore by mutableStateOf(false)
        private set

    var searchText by mutableStateOf("")
    var activeQuery by mutableStateOf("")
        private set
    var view by mutableStateOf(MailView.INBOX)
        private set
    var selectedAlias by mutableStateOf<Int?>(null)
        private set
    /** A selected folder replaces the view as the list's slice. */
    var selectedFolder by mutableStateOf<MailFolder?>(null)
        private set

    private var page = 1
    /** Ignores stale in-flight loads after the view/mailbox/search changes. */
    private var loadGeneration = 0

    /** The mailbox the list is scoped to, when a specific one is selected. */
    val selectedMailbox: Mailbox?
        get() = selectedAlias?.let { alias -> home?.mailboxes?.firstOrNull { it.aliasId == alias } }

    /** The mailbox whose folder rail applies: the selected one, or the only
     *  grant when there is exactly one (the switcher is hidden then). */
    val effectiveMailbox: Mailbox?
        get() = selectedMailbox ?: home?.mailboxes?.singleOrNull()

    val title: String
        get() = when {
            activeQuery.isNotEmpty() -> "Search"
            selectedFolder != null -> selectedFolder!!.name
            else -> view.title
        }

    /** First load: mailboxes and the initial thread page together. */
    suspend fun initialLoad() {
        phase = Phase.Loading
        try {
            coroutineScope {
                val homeTask = async { api.mailboxes() }
                val pageTask = async {
                    api.threadList(selectedAlias, view, selectedFolder?.id, activeQuery, 1)
                }
                home = homeTask.await()
                apply(pageTask.await(), reset = true)
            }
            phase = Phase.Loaded
        } catch (e: Exception) {
            phase = Phase.Failed(displayMessage(e))
        }
    }

    /** Re-read the current slice from page 1 (pull-to-refresh, after actions,
     *  after a view/mailbox/search change). Keeps showing the last-good list
     *  while it runs; failures surface only when nothing is loaded yet. */
    suspend fun reload(refreshMailboxes: Boolean = false) {
        loadGeneration += 1
        val generation = loadGeneration
        try {
            if (refreshMailboxes) {
                home = api.mailboxes()
            }
            val firstPage = api.threadList(selectedAlias, view, selectedFolder?.id, activeQuery, 1)
            if (generation != loadGeneration) return
            apply(firstPage, reset = true)
            phase = Phase.Loaded
        } catch (e: Exception) {
            if (generation != loadGeneration) return
            if (phase is Phase.Loaded) return
            phase = Phase.Failed(displayMessage(e))
        }
    }

    suspend fun loadMore() {
        if (!hasMore || isLoadingMore) return
        isLoadingMore = true
        val generation = loadGeneration
        try {
            val next = api.threadList(selectedAlias, view, selectedFolder?.id, activeQuery, page + 1)
            if (generation == loadGeneration) apply(next, reset = false)
        } catch (e: Exception) {
            // Paging failures are silent; the next scroll retries.
        } finally {
            isLoadingMore = false
        }
    }

    private fun apply(pageData: ThreadPage, reset: Boolean) {
        threads = if (reset) {
            pageData.threads
        } else {
            val known = threads.map { it.threadKey }.toHashSet()
            threads + pageData.threads.filter { !known.contains(it.threadKey) }
        }
        page = pageData.page
        hasMore = pageData.hasMore
    }

    // MARK: Slice changes

    suspend fun select(newView: MailView) {
        if (newView == view && selectedFolder == null) return
        view = newView
        selectedFolder = null
        reload()
    }

    suspend fun select(alias: Int?) {
        if (alias == selectedAlias) return
        selectedAlias = alias
        selectedFolder = null
        reload()
    }

    /** Scope the list to one folder of one mailbox (the folder rail). */
    suspend fun select(folder: MailFolder, ofAlias: Int) {
        if (selectedFolder?.id == folder.id && selectedAlias == ofAlias) return
        selectedAlias = ofAlias
        selectedFolder = folder
        reload()
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

    // MARK: Row actions (list swipes; detail actions patch through here too)

    /** Run a thread action and patch the row locally so the list responds
     *  instantly; failures fall back to a reload to reconcile. */
    suspend fun perform(action: String, thread: ThreadSummary) {
        try {
            api.threadAction(action, thread.threadKey, selectedAlias)
        } catch (e: Exception) {
            reload()
            return
        }
        when (action) {
            "mark_read" -> patch(thread.threadKey) { it.copy(unreadCount = 0) }
            "mark_unread" -> patch(thread.threadKey) { it.copy(unreadCount = maxOf(1, it.unreadCount)) }
            "star" -> patch(thread.threadKey) { it.copy(isStarred = true) }
            "unstar" -> patch(thread.threadKey) { it.copy(isStarred = false) }
            "archive" ->
                if (view == MailView.INBOX && selectedFolder == null) {
                    remove(thread.threadKey)
                } else {
                    patch(thread.threadKey) { it.copy(isArchived = true) }
                }
            "unarchive" -> patch(thread.threadKey) { it.copy(isArchived = false) }
            "delete", "mark_spam", "mark_not_spam" -> remove(thread.threadKey)
            else -> reload()
        }
    }

    /** Local patch used by the detail screen when it changes thread state. */
    fun patch(threadKey: String, mutate: (ThreadSummary) -> ThreadSummary) {
        threads = threads.map { if (it.threadKey == threadKey) mutate(it) else it }
    }

    fun remove(threadKey: String) {
        threads = threads.filter { it.threadKey != threadKey }
    }

    private fun displayMessage(e: Exception): String =
        (e as? JoineryApiError)?.displayMessage ?: (e.message ?: "Something went wrong.")
}
