package com.getjoinery.mail

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Star
import androidx.compose.material.icons.outlined.Archive
import androidx.compose.material.icons.outlined.Check
import androidx.compose.material.icons.outlined.Clear
import androidx.compose.material.icons.outlined.Drafts
import androidx.compose.material.icons.outlined.Edit
import androidx.compose.material.icons.outlined.FilterList
import androidx.compose.material.icons.outlined.Folder
import androidx.compose.material.icons.outlined.MarkEmailUnread
import androidx.compose.material.icons.outlined.Search
import androidx.compose.material.icons.outlined.StarBorder
import androidx.compose.material.icons.outlined.Unarchive
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.SwipeToDismissBox
import androidx.compose.material3.SwipeToDismissBoxValue
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.pulltorefresh.PullToRefreshContainer
import androidx.compose.material3.pulltorefresh.rememberPullToRefreshState
import androidx.compose.material3.rememberSwipeToDismissBoxState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.rememberUpdatedState
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.input.nestedscroll.nestedScroll
import androidx.compose.ui.platform.LocalLifecycleOwner
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleEventObserver
import com.getjoinery.android.ApiClient
import com.getjoinery.android.NativeScreenRegistry
import kotlinx.coroutines.launch

/**
 * Module entry point: call once at app launch to make the `mailbox`
 * navigation screen available. The server flips the Email entry to
 * `{type: "native", screen: "mailbox"}`; builds without this module keep
 * loading the web reader via the entry's fallback URL.
 */
object JoineryMail {
    fun registerScreens() {
        NativeScreenRegistry.register("mailbox") { context ->
            MailboxScreen(client = context.session.client)
        }
    }
}

/** A compose invocation: what mode, and (for reply/reply-all/forward) which
 *  message it responds to. New-message compose has no source to quote — the
 *  sending identity comes from a From picker over the granted mailboxes. */
data class ComposeRequest(val mode: ComposeMode, val source: MailMessage?) {
    companion object {
        val new = ComposeRequest(ComposeMode.NEW, null)
    }
}

/**
 * The native mailbox: a Gmail-style thread list over the granted mailboxes,
 * with view switching (Inbox / Starred / All Mail / Spam), the folder/label
 * rail, server-side search, swipe triage, paging, and pull-to-refresh.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun MailboxScreen(client: ApiClient) {
    val store = remember { MailboxStore(MailApi(client)) }
    val scope = rememberCoroutineScope()
    val listState = rememberLazyListState()
    var openThread by remember { mutableStateOf<ThreadSummary?>(null) }
    var composeRequest by remember { mutableStateOf<ComposeRequest?>(null) }

    LaunchedEffect(Unit) {
        if (store.phase is MailboxStore.Phase.Loading) store.initialLoad()
    }

    // Foreground refresh: mail may have arrived while the app was away. Skips
    // the initial resume (the LaunchedEffect above already loaded).
    val lifecycleOwner = LocalLifecycleOwner.current
    DisposableEffect(lifecycleOwner) {
        val observer = LifecycleEventObserver { _, event ->
            if (event == Lifecycle.Event.ON_RESUME && store.phase !is MailboxStore.Phase.Loading) {
                scope.launch { store.reload(refreshMailboxes = true) }
            }
        }
        lifecycleOwner.lifecycle.addObserver(observer)
        onDispose { lifecycleOwner.lifecycle.removeObserver(observer) }
    }

    val opened = openThread
    if (opened != null) {
        ThreadDetailScreen(store = store, summary = opened, onClose = { openThread = null })
    } else {
        MailboxListScaffold(
            store = store,
            listState = listState,
            onOpenThread = { openThread = it },
            onNewMessage = { composeRequest = ComposeRequest.new },
        )
    }

    composeRequest?.let { request ->
        ComposeSheet(
            api = store.api,
            request = request,
            mailboxes = store.home?.mailboxes ?: emptyList(),
            preselectedAlias = store.selectedAlias,
            onDismiss = { composeRequest = null },
            onSent = {
                composeRequest = null
                scope.launch { store.reload(refreshMailboxes = true) }
            },
        )
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun MailboxListScaffold(
    store: MailboxStore,
    listState: androidx.compose.foundation.lazy.LazyListState,
    onOpenThread: (ThreadSummary) -> Unit,
    onNewMessage: () -> Unit,
) {
    val scope = rememberCoroutineScope()

    Scaffold(topBar = {
        TopAppBar(
            title = { Text(store.title, maxLines = 1, overflow = TextOverflow.Ellipsis) },
            actions = {
                FilterMenuButton(store)
                IconButton(
                    onClick = onNewMessage,
                    enabled = store.home?.canCompose == true,
                    modifier = Modifier.testTag("mail_new_message"),
                ) {
                    Icon(Icons.Outlined.Edit, contentDescription = "New message")
                }
            },
        )
    }) { padding ->
        Column(Modifier.fillMaxSize().padding(padding)) {
            SearchRow(store)
            when (val phase = store.phase) {
                is MailboxStore.Phase.Loading ->
                    Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                        CircularProgressIndicator(Modifier.testTag("mail_loading"))
                    }
                is MailboxStore.Phase.Failed ->
                    Box(Modifier.fillMaxSize().padding(24.dp), contentAlignment = Alignment.Center) {
                        Column(
                            horizontalAlignment = Alignment.CenterHorizontally,
                            verticalArrangement = Arrangement.spacedBy(12.dp),
                        ) {
                            Text(
                                phase.message,
                                color = MaterialTheme.colorScheme.onSurfaceVariant,
                                modifier = Modifier.testTag("mail_error"),
                            )
                            androidx.compose.material3.Button(
                                onClick = { scope.launch { store.initialLoad() } },
                                modifier = Modifier.testTag("mail_retry"),
                            ) { Text("Try Again") }
                        }
                    }
                is MailboxStore.Phase.Loaded ->
                    ThreadListContent(store, listState, onOpenThread)
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun ThreadListContent(
    store: MailboxStore,
    listState: androidx.compose.foundation.lazy.LazyListState,
    onOpenThread: (ThreadSummary) -> Unit,
) {
    val scope = rememberCoroutineScope()
    val refreshState = rememberPullToRefreshState()
    if (refreshState.isRefreshing) {
        LaunchedEffect(true) {
            store.reload(refreshMailboxes = true)
            refreshState.endRefresh()
        }
    }

    Box(Modifier.fillMaxSize().nestedScroll(refreshState.nestedScrollConnection)) {
        LazyColumn(
            state = listState,
            modifier = Modifier.fillMaxSize().testTag("mail_list"),
        ) {
            if (store.threads.isEmpty()) {
                item { EmptyState(store) }
            }
            items(store.threads.size, key = { store.threads[it].threadKey }) { index ->
                val thread = store.threads[index]
                SwipeableThreadRow(
                    thread = thread,
                    allowArchive = store.view != MailView.SPAM,
                    onOpen = { onOpenThread(thread) },
                    onToggleRead = { t ->
                        scope.launch {
                            store.perform(if (t.hasUnread) "mark_read" else "mark_unread", t)
                        }
                    },
                    onToggleArchive = { t ->
                        scope.launch {
                            store.perform(if (t.isArchived) "unarchive" else "archive", t)
                        }
                    },
                    onToggleStar = { t ->
                        scope.launch {
                            store.perform(if (t.isStarred) "unstar" else "star", t)
                        }
                    },
                )
                HorizontalDivider(color = MaterialTheme.colorScheme.outlineVariant)
                if (index == store.threads.lastIndex) {
                    LaunchedEffect(thread.threadKey) { store.loadMore() }
                }
            }
            if (store.isLoadingMore) {
                item {
                    Box(Modifier.fillMaxWidth().padding(16.dp), contentAlignment = Alignment.Center) {
                        CircularProgressIndicator(Modifier.size(24.dp))
                    }
                }
            }
        }
        PullToRefreshContainer(
            state = refreshState,
            modifier = Modifier.align(Alignment.TopCenter),
        )
    }
}

@Composable
private fun EmptyState(store: MailboxStore) {
    val text = when {
        store.activeQuery.isNotEmpty() -> "No results for “${store.activeQuery}”"
        store.home?.mailboxes.isNullOrEmpty() -> "No mailbox has been granted to this account."
        store.selectedFolder != null -> "Nothing in ${store.selectedFolder!!.name}."
        store.view == MailView.INBOX -> "Inbox zero — nothing here."
        store.view == MailView.STARRED -> "No starred conversations."
        store.view == MailView.ALL -> "No mail yet."
        else -> "No spam. Nice."
    }
    Column(
        Modifier.fillMaxWidth().padding(vertical = 60.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.spacedBy(8.dp),
    ) {
        Icon(
            store.view.icon,
            contentDescription = null,
            tint = MaterialTheme.colorScheme.onSurfaceVariant,
            modifier = Modifier.size(40.dp),
        )
        Text(
            text,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
            modifier = Modifier.testTag("mail_empty"),
        )
    }
}

@Composable
private fun SearchRow(store: MailboxStore) {
    val scope = rememberCoroutineScope()
    OutlinedTextField(
        value = store.searchText,
        onValueChange = { text ->
            store.searchText = text
            if (text.isEmpty()) scope.launch { store.clearSearch() }
        },
        placeholder = { Text("Search mail") },
        leadingIcon = { Icon(Icons.Outlined.Search, contentDescription = null) },
        trailingIcon = {
            if (store.searchText.isNotEmpty()) {
                IconButton(onClick = {
                    store.searchText = ""
                    scope.launch { store.clearSearch() }
                }) {
                    Icon(Icons.Outlined.Clear, contentDescription = "Clear search")
                }
            }
        },
        singleLine = true,
        keyboardOptions = KeyboardOptions(imeAction = ImeAction.Search),
        keyboardActions = KeyboardActions(onSearch = { scope.launch { store.submitSearch() } }),
        modifier = Modifier
            .fillMaxWidth()
            .padding(horizontal = 16.dp, vertical = 4.dp)
            .testTag("mail_search"),
    )
}

/** The view / mailbox / folder switcher — one menu, mirroring the iOS toolbar
 *  menu plus the web reader's folder rail. */
@Composable
private fun FilterMenuButton(store: MailboxStore) {
    val scope = rememberCoroutineScope()
    var open by remember { mutableStateOf(false) }

    IconButton(onClick = { open = true }, modifier = Modifier.testTag("mail_view_menu")) {
        Icon(Icons.Outlined.FilterList, contentDescription = "Views and mailboxes")
    }
    DropdownMenu(expanded = open, onDismissRequest = { open = false }) {
        MailView.entries.forEach { view ->
            DropdownMenuItem(
                text = { Text(view.title) },
                leadingIcon = { Icon(view.icon, contentDescription = null) },
                trailingIcon = {
                    if (store.view == view && store.selectedFolder == null) CheckMark()
                },
                onClick = {
                    open = false
                    scope.launch { store.select(view) }
                },
            )
        }
        val mailboxes = store.home?.mailboxes ?: emptyList()
        if (mailboxes.size > 1) {
            HorizontalDivider()
            DropdownMenuItem(
                text = { Text("All mailboxes") },
                trailingIcon = { if (store.selectedAlias == null) CheckMark() },
                onClick = {
                    open = false
                    scope.launch { store.select(null as Int?) }
                },
            )
            mailboxes.forEach { box ->
                DropdownMenuItem(
                    text = { Text(box.address, maxLines = 1, overflow = TextOverflow.Ellipsis) },
                    trailingIcon = { if (store.selectedAlias == box.aliasId) CheckMark() },
                    onClick = {
                        open = false
                        scope.launch { store.select(box.aliasId) }
                    },
                )
            }
        }
        val railBox = store.effectiveMailbox
        if (railBox != null && railBox.folders.isNotEmpty()) {
            HorizontalDivider()
            railBox.folders.forEach { folder ->
                DropdownMenuItem(
                    text = { Text(folder.name, maxLines = 1, overflow = TextOverflow.Ellipsis) },
                    leadingIcon = { Icon(Icons.Outlined.Folder, contentDescription = null) },
                    trailingIcon = { if (store.selectedFolder?.id == folder.id) CheckMark() },
                    onClick = {
                        open = false
                        scope.launch { store.select(folder, railBox.aliasId) }
                    },
                )
            }
        }
    }
}

@Composable
private fun CheckMark() {
    Icon(
        Icons.Outlined.Check,
        contentDescription = "Selected",
        tint = MaterialTheme.colorScheme.primary,
    )
}

// MARK: - Rows

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun SwipeableThreadRow(
    thread: ThreadSummary,
    allowArchive: Boolean,
    onOpen: () -> Unit,
    onToggleRead: (ThreadSummary) -> Unit,
    onToggleArchive: (ThreadSummary) -> Unit,
    onToggleStar: (ThreadSummary) -> Unit,
) {
    // The state's confirmValueChange is captured once; read the live row
    // through rememberUpdatedState so patches don't act on a stale copy.
    val current = rememberUpdatedState(thread)
    val readCallback = rememberUpdatedState(onToggleRead)
    val archiveCallback = rememberUpdatedState(onToggleArchive)
    val dismissState = rememberSwipeToDismissBoxState(
        confirmValueChange = { value ->
            when (value) {
                SwipeToDismissBoxValue.StartToEnd -> readCallback.value(current.value)
                SwipeToDismissBoxValue.EndToStart -> archiveCallback.value(current.value)
                else -> {}
            }
            false // Snap back; local patches (or removal) update the row.
        },
    )

    SwipeToDismissBox(
        state = dismissState,
        enableDismissFromStartToEnd = true,
        enableDismissFromEndToStart = allowArchive,
        backgroundContent = {
            val target = dismissState.dismissDirection
            val (color, icon, alignment) = when (target) {
                SwipeToDismissBoxValue.StartToEnd -> Triple(
                    Color(0xFF1E88E5),
                    if (thread.hasUnread) Icons.Outlined.Drafts else Icons.Outlined.MarkEmailUnread,
                    Alignment.CenterStart,
                )
                SwipeToDismissBoxValue.EndToStart -> Triple(
                    Color(0xFF43A047),
                    if (thread.isArchived) Icons.Outlined.Unarchive else Icons.Outlined.Archive,
                    Alignment.CenterEnd,
                )
                else -> return@SwipeToDismissBox
            }
            Box(
                Modifier.fillMaxSize().background(color).padding(horizontal = 24.dp),
                contentAlignment = alignment,
            ) {
                Icon(icon, contentDescription = null, tint = Color.White)
            }
        },
    ) {
        ThreadRow(thread, onOpen, onToggleStar)
    }
}

/** One Gmail-style list row: colored initial avatar, sender + date line,
 *  subject line, snippet + star line. Unread rows render bold. */
@Composable
private fun ThreadRow(
    thread: ThreadSummary,
    onOpen: () -> Unit,
    onToggleStar: (ThreadSummary) -> Unit,
) {
    val unread = thread.hasUnread
    Row(
        Modifier
            .fillMaxWidth()
            .background(MaterialTheme.colorScheme.surface)
            .clickable(onClick = onOpen)
            .padding(start = 16.dp, end = 12.dp, top = 10.dp, bottom = 10.dp)
            .testTag("mail_row"),
        horizontalArrangement = Arrangement.spacedBy(12.dp),
        verticalAlignment = Alignment.Top,
    ) {
        SenderAvatar(seed = thread.sender, size = 40.dp, showUnreadDot = unread)
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                val name = MailDisplay.senderName(thread.sender)
                Text(
                    if (thread.messageCount > 1) "$name ${thread.messageCount}" else name,
                    style = MaterialTheme.typography.bodyMedium,
                    fontWeight = if (unread) FontWeight.SemiBold else FontWeight.Normal,
                    color = if (unread) MaterialTheme.colorScheme.onSurface
                    else MaterialTheme.colorScheme.onSurfaceVariant,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis,
                    modifier = Modifier.weight(1f),
                )
                Spacer(Modifier.size(8.dp))
                Text(
                    MailDisplay.listStamp(thread.latestTime),
                    style = MaterialTheme.typography.labelSmall,
                    fontWeight = if (unread) FontWeight.SemiBold else FontWeight.Normal,
                    color = if (unread) MaterialTheme.colorScheme.primary
                    else MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
            Row(verticalAlignment = Alignment.Top, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(1.dp)) {
                    Text(
                        thread.subject.ifEmpty { "(no subject)" },
                        style = MaterialTheme.typography.bodyMedium,
                        fontWeight = if (unread) FontWeight.SemiBold else FontWeight.Normal,
                        maxLines = 1,
                        overflow = TextOverflow.Ellipsis,
                    )
                    Text(
                        thread.snippet,
                        style = MaterialTheme.typography.bodyMedium,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                        maxLines = 1,
                        overflow = TextOverflow.Ellipsis,
                    )
                }
                IconButton(
                    onClick = { onToggleStar(thread) },
                    modifier = Modifier.size(28.dp).testTag("mail_row_star"),
                ) {
                    Icon(
                        if (thread.isStarred) Icons.Filled.Star else Icons.Outlined.StarBorder,
                        contentDescription = if (thread.isStarred) "Unstar" else "Star",
                        tint = if (thread.isStarred) Color(0xFFF9A825)
                        else MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }
            }
        }
    }
}

/** Gmail-style colored initial circle, hue stable per sender address. */
@Composable
internal fun SenderAvatar(
    seed: String,
    size: androidx.compose.ui.unit.Dp,
    showUnreadDot: Boolean = false,
    initialOverride: String? = null,
) {
    val palette = listOf(
        Color(0xFFDB3335), Color(0xFFF57D00), Color(0xFFFABD05), Color(0xFF33A854),
        Color(0xFF029EA3), Color(0xFF4285F5), Color(0xFF664FA3), Color(0xFFC22E78),
    )
    val index = MailDisplay.avatarColorIndex(seed, palette.size)
    val initial = initialOverride ?: MailDisplay.senderName(seed).take(1).uppercase()
    Box {
        Box(
            Modifier.size(size).background(palette[index], CircleShape),
            contentAlignment = Alignment.Center,
        ) {
            Text(initial, color = Color.White, style = MaterialTheme.typography.titleMedium)
        }
        if (showUnreadDot) {
            Box(
                Modifier
                    .align(Alignment.TopEnd)
                    .size(10.dp)
                    .background(MaterialTheme.colorScheme.primary, CircleShape),
            )
        }
    }
}
