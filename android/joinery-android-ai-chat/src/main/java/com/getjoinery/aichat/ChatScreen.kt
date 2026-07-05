package com.getjoinery.aichat

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.ExperimentalFoundationApi
import androidx.compose.foundation.combinedClickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.Chat
import androidx.compose.material.icons.filled.PushPin
import androidx.compose.material.icons.outlined.AutoAwesome
import androidx.compose.material.icons.outlined.Clear
import androidx.compose.material.icons.outlined.Delete
import androidx.compose.material.icons.outlined.Edit
import androidx.compose.material.icons.outlined.PushPin
import androidx.compose.material.icons.outlined.Search
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
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
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.pulltorefresh.PullToRefreshContainer
import androidx.compose.material3.pulltorefresh.rememberPullToRefreshState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.input.nestedscroll.nestedScroll
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import com.getjoinery.android.ApiClient
import com.getjoinery.android.NativeScreenRegistry
import kotlinx.coroutines.launch

/**
 * Module entry point: call once at app launch to make the `ai_chat`
 * navigation screen available. The server flips the AI Chat entry to
 * `{type: "native", screen: "ai_chat"}`; builds without this module keep
 * loading the web chat via the entry's fallback URL.
 */
object JoineryAIChat {
    fun registerScreens() {
        NativeScreenRegistry.register("ai_chat") { context ->
            ChatScreen(client = context.session.client)
        }
    }
}

/** Which thread is open: an existing conversation or a brand-new chat. */
private sealed class OpenThread {
    object New : OpenThread()
    data class Existing(val id: Int, val title: String) : OpenThread()
}

/**
 * The native AI chat: a list of the member's conversations with search, pin,
 * rename, and delete, opening into a threaded chat with the assistant.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ChatScreen(client: ApiClient) {
    val api = remember { ChatApi(client) }
    val store = remember { ChatListStore(api) }
    val scope = rememberCoroutineScope()
    var openThread by remember { mutableStateOf<OpenThread?>(null) }
    var renaming by remember { mutableStateOf<ChatConversation?>(null) }
    var renameText by remember { mutableStateOf("") }

    LaunchedEffect(Unit) {
        if (store.phase is ChatListStore.Phase.Loading) store.initialLoad()
    }

    val open = openThread
    if (open != null) {
        val (id, title) = when (open) {
            is OpenThread.New -> null to "New chat"
            is OpenThread.Existing -> open.id to open.title
        }
        ChatThreadView(
            api = api,
            conversationId = id,
            title = title,
            onClose = {
                openThread = null
                // Re-read so a new/renamed conversation and its bumped order
                // show up.
                scope.launch { store.reload() }
            },
        )
        return
    }

    Scaffold(topBar = {
        TopAppBar(
            title = { Text("AI Chat") },
            actions = {
                IconButton(
                    onClick = { openThread = OpenThread.New },
                    modifier = Modifier.testTag("chat_new"),
                ) {
                    Icon(Icons.Outlined.Edit, contentDescription = "New chat")
                }
            },
        )
    }) { padding ->
        Column(Modifier.fillMaxSize().padding(padding)) {
            SearchRow(store)
            when (val phase = store.phase) {
                is ChatListStore.Phase.Loading ->
                    Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                        CircularProgressIndicator(Modifier.testTag("chat_loading"))
                    }
                is ChatListStore.Phase.Failed ->
                    Box(Modifier.fillMaxSize().padding(24.dp), contentAlignment = Alignment.Center) {
                        Column(
                            horizontalAlignment = Alignment.CenterHorizontally,
                            verticalArrangement = Arrangement.spacedBy(12.dp),
                        ) {
                            Text(
                                phase.message,
                                color = MaterialTheme.colorScheme.onSurfaceVariant,
                                modifier = Modifier.testTag("chat_error"),
                            )
                            Button(
                                onClick = { scope.launch { store.initialLoad() } },
                                modifier = Modifier.testTag("chat_retry"),
                            ) { Text("Try Again") }
                        }
                    }
                is ChatListStore.Phase.Loaded ->
                    ConversationList(
                        store = store,
                        onOpen = { openThread = OpenThread.Existing(it.id, it.title) },
                        onNew = { openThread = OpenThread.New },
                        onRename = { renaming = it; renameText = it.title },
                    )
            }
        }
    }

    renaming?.let { conversation ->
        AlertDialog(
            onDismissRequest = { renaming = null },
            title = { Text("Rename chat") },
            text = {
                OutlinedTextField(
                    value = renameText,
                    onValueChange = { renameText = it },
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth(),
                )
            },
            confirmButton = {
                TextButton(onClick = {
                    val title = renameText
                    renaming = null
                    scope.launch { store.rename(conversation, title) }
                }) { Text("Save") }
            },
            dismissButton = {
                TextButton(onClick = { renaming = null }) { Text("Cancel") }
            },
        )
    }
}

@OptIn(ExperimentalMaterial3Api::class, ExperimentalFoundationApi::class)
@Composable
private fun ConversationList(
    store: ChatListStore,
    onOpen: (ChatConversation) -> Unit,
    onNew: () -> Unit,
    onRename: (ChatConversation) -> Unit,
) {
    val scope = rememberCoroutineScope()
    val refreshState = rememberPullToRefreshState()
    if (refreshState.isRefreshing) {
        LaunchedEffect(true) {
            store.reload()
            refreshState.endRefresh()
        }
    }
    var menuFor by remember { mutableStateOf<Int?>(null) }

    Box(Modifier.fillMaxSize().nestedScroll(refreshState.nestedScrollConnection)) {
        LazyColumn(Modifier.fillMaxSize().testTag("chat_list")) {
            if (store.conversations.isEmpty()) {
                item {
                    Column(
                        Modifier.fillMaxWidth().padding(vertical = 60.dp),
                        horizontalAlignment = Alignment.CenterHorizontally,
                        verticalArrangement = Arrangement.spacedBy(10.dp),
                    ) {
                        Icon(
                            Icons.Outlined.AutoAwesome,
                            contentDescription = null,
                            tint = MaterialTheme.colorScheme.onSurfaceVariant,
                            modifier = Modifier.size(40.dp),
                        )
                        Text(
                            if (store.activeQuery.isEmpty()) "No chats yet."
                            else "No results for “${store.activeQuery}”",
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                            modifier = Modifier.testTag("chat_empty"),
                        )
                        if (store.activeQuery.isEmpty()) {
                            Button(onClick = onNew) { Text("Start a chat") }
                        }
                    }
                }
            }
            items(store.conversations.size, key = { store.conversations[it].id }) { index ->
                val conversation = store.conversations[index]
                Box {
                    Row(
                        Modifier
                            .fillMaxWidth()
                            .combinedClickable(
                                onClick = { onOpen(conversation) },
                                onLongClick = { menuFor = conversation.id },
                            )
                            .padding(horizontal = 16.dp, vertical = 14.dp)
                            .testTag("chat_row"),
                        horizontalArrangement = Arrangement.spacedBy(10.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Icon(
                            if (conversation.pinned) Icons.Filled.PushPin
                            else Icons.AutoMirrored.Outlined.Chat,
                            contentDescription = null,
                            tint = if (conversation.pinned) MaterialTheme.colorScheme.primary
                            else MaterialTheme.colorScheme.onSurfaceVariant,
                            modifier = Modifier.size(18.dp),
                        )
                        Text(
                            conversation.title.ifEmpty { "Untitled" },
                            maxLines = 1,
                            overflow = TextOverflow.Ellipsis,
                        )
                    }
                    DropdownMenu(
                        expanded = menuFor == conversation.id,
                        onDismissRequest = { menuFor = null },
                    ) {
                        DropdownMenuItem(
                            text = { Text("Rename") },
                            leadingIcon = { Icon(Icons.Outlined.Edit, contentDescription = null) },
                            onClick = { menuFor = null; onRename(conversation) },
                        )
                        DropdownMenuItem(
                            text = { Text(if (conversation.pinned) "Unpin" else "Pin") },
                            leadingIcon = { Icon(Icons.Outlined.PushPin, contentDescription = null) },
                            onClick = {
                                menuFor = null
                                scope.launch { store.togglePin(conversation) }
                            },
                        )
                        DropdownMenuItem(
                            text = { Text("Delete") },
                            leadingIcon = {
                                Icon(Icons.Outlined.Delete, contentDescription = null,
                                    tint = MaterialTheme.colorScheme.error)
                            },
                            onClick = {
                                menuFor = null
                                scope.launch { store.delete(conversation) }
                            },
                        )
                    }
                }
                HorizontalDivider(color = MaterialTheme.colorScheme.outlineVariant)
            }
        }
        PullToRefreshContainer(
            state = refreshState,
            modifier = Modifier.align(Alignment.TopCenter),
        )
    }
}

@Composable
private fun SearchRow(store: ChatListStore) {
    val scope = rememberCoroutineScope()
    OutlinedTextField(
        value = store.searchText,
        onValueChange = { text ->
            store.searchText = text
            if (text.isEmpty()) scope.launch { store.clearSearch() }
        },
        placeholder = { Text("Search chats") },
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
            .testTag("chat_search"),
    )
}
