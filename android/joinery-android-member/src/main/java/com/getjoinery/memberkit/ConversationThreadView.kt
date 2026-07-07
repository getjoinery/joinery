package com.getjoinery.memberkit

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.sizeIn
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.selection.SelectionContainer
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.automirrored.filled.Send
import androidx.compose.material.icons.filled.MoreVert
import androidx.compose.material.icons.outlined.Delete
import androidx.compose.material.icons.outlined.Notifications
import androidx.compose.material.icons.outlined.NotificationsOff
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import com.getjoinery.android.JoineryApiError
import kotlinx.coroutines.launch

/**
 * One conversation: message bubbles in a scroll with a compose bar pinned to
 * the bottom, cursor pagination on scroll, and a mute/delete menu. Opening the
 * thread marks it read server-side as a side effect of the load call.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ConversationThreadView(
    api: ConversationApi,
    origin: ThreadOrigin,
    onClose: () -> Unit,
) {
    val scope = rememberCoroutineScope()
    val store = remember { ConversationThreadStore(api, origin) }
    var showDeleteConfirm by remember { mutableStateOf(false) }
    var deleteError by remember { mutableStateOf<String?>(null) }
    var menuOpen by remember { mutableStateOf(false) }

    BackHandler(onBack = onClose)
    LaunchedEffect(Unit) {
        if (store.phase is MemberPhase.Loading) store.load()
    }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text(store.otherDisplayName, maxLines = 1, overflow = TextOverflow.Ellipsis) },
                navigationIcon = {
                    IconButton(onClick = onClose) {
                        Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "Back")
                    }
                },
                actions = {
                    if (store.conversationId != null) {
                        IconButton(onClick = { menuOpen = true }, modifier = Modifier.testTag("member_conversation_thread_menu")) {
                            Icon(Icons.Filled.MoreVert, contentDescription = "More actions")
                        }
                        DropdownMenu(expanded = menuOpen, onDismissRequest = { menuOpen = false }) {
                            DropdownMenuItem(
                                text = { Text(if (store.isMuted) "Unmute" else "Mute") },
                                leadingIcon = {
                                    Icon(
                                        if (store.isMuted) Icons.Outlined.Notifications else Icons.Outlined.NotificationsOff,
                                        contentDescription = null,
                                    )
                                },
                                onClick = {
                                    menuOpen = false
                                    scope.launch { store.setMuted(!store.isMuted) }
                                },
                            )
                            DropdownMenuItem(
                                text = { Text("Delete") },
                                leadingIcon = {
                                    Icon(Icons.Outlined.Delete, contentDescription = null, tint = MaterialTheme.colorScheme.error)
                                },
                                onClick = { menuOpen = false; showDeleteConfirm = true },
                            )
                        }
                    }
                },
            )
        },
        bottomBar = { Composer(store) },
    ) { padding ->
        when (val phase = store.phase) {
            is MemberPhase.Loading ->
                Box(Modifier.fillMaxSize().padding(padding), contentAlignment = Alignment.Center) {
                    CircularProgressIndicator(Modifier.testTag("member_conversation_thread_loading"))
                }
            is MemberPhase.Failed ->
                RetryBox(phase.message, "member_conversation_thread_error", "member_conversation_thread_retry", Modifier.padding(padding)) {
                    scope.launch { store.load() }
                }
            is MemberPhase.Loaded ->
                Transcript(store, Modifier.padding(padding))
        }
    }

    if (showDeleteConfirm) {
        AlertDialog(
            onDismissRequest = { showDeleteConfirm = false },
            title = { Text("Delete this conversation?") },
            confirmButton = {
                TextButton(onClick = {
                    showDeleteConfirm = false
                    scope.launch {
                        try {
                            store.delete()
                            onClose()
                        } catch (e: Exception) {
                            deleteError = (e as? JoineryApiError)?.displayMessage ?: "Could not delete the conversation."
                        }
                    }
                }) { Text("Delete") }
            },
            dismissButton = { TextButton(onClick = { showDeleteConfirm = false }) { Text("Cancel") } },
        )
    }

    deleteError?.let { message ->
        AlertDialog(
            onDismissRequest = { deleteError = null },
            title = { Text("Could not delete") },
            text = { Text(message) },
            confirmButton = { TextButton(onClick = { deleteError = null }) { Text("OK") } },
        )
    }

    store.sendError?.let { message ->
        AlertDialog(
            onDismissRequest = { store.clearSendError() },
            title = { Text("Could not send") },
            text = { Text(message) },
            confirmButton = { TextButton(onClick = { store.clearSendError() }) { Text("OK") } },
        )
    }
}

@Composable
private fun Transcript(store: ConversationThreadStore, modifier: Modifier = Modifier) {
    val scope = rememberCoroutineScope()
    val listState = rememberLazyListState()

    // Keep the newest message in view as the transcript grows.
    LaunchedEffect(store.messages.size) {
        if (store.messages.isNotEmpty()) listState.animateScrollToItem(store.messages.size - 1)
    }

    LazyColumn(
        state = listState,
        modifier = modifier.fillMaxSize().testTag("member_conversation_transcript"),
        verticalArrangement = Arrangement.spacedBy(10.dp),
        contentPadding = androidx.compose.foundation.layout.PaddingValues(horizontal = 14.dp, vertical = 12.dp),
    ) {
        if (store.hasMore) {
            item {
                Box(Modifier.fillMaxWidth(), contentAlignment = Alignment.Center) {
                    if (store.isLoadingMore) {
                        CircularProgressIndicator(Modifier.size(20.dp))
                    } else {
                        TextButton(onClick = { scope.launch { store.loadMore() } }) { Text("Load more") }
                    }
                }
            }
        }
        if (store.messages.isEmpty()) {
            item {
                Text(
                    "Say hello.",
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                    textAlign = TextAlign.Center,
                    modifier = Modifier.fillMaxWidth().padding(vertical = 60.dp).testTag("member_conversation_thread_empty"),
                )
            }
        }
        items(store.messages.size, key = { store.messages[it].messageId }) { index ->
            MessageBubble(store.messages[index])
        }
    }
}

@Composable
private fun MessageBubble(message: ThreadMessage) {
    Row(Modifier.fillMaxWidth()) {
        if (message.isMine) Spacer(Modifier.weight(1f).sizeIn(minWidth = 40.dp))
        Column(
            horizontalAlignment = if (message.isMine) Alignment.End else Alignment.Start,
            verticalArrangement = Arrangement.spacedBy(3.dp),
            modifier = Modifier.testTag(if (message.isMine) "member_conversation_message_mine" else "member_conversation_message_theirs"),
        ) {
            Surface(
                shape = RoundedCornerShape(18.dp),
                color = if (message.isMine) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.surfaceVariant,
            ) {
                SelectionContainer {
                    Text(
                        message.body,
                        color = if (message.isMine) MaterialTheme.colorScheme.onPrimary else MaterialTheme.colorScheme.onSurface,
                        modifier = Modifier.padding(horizontal = 14.dp, vertical = 9.dp),
                    )
                }
            }
            Text(
                MemberDisplay.dateTimeLabel(message.time),
                style = MaterialTheme.typography.labelSmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }
        if (!message.isMine) Spacer(Modifier.weight(1f).sizeIn(minWidth = 40.dp))
    }
}

@Composable
private fun Composer(store: ConversationThreadStore) {
    val scope = rememberCoroutineScope()
    Surface(tonalElevation = 3.dp) {
        Row(
            Modifier.fillMaxWidth().padding(horizontal = 12.dp, vertical = 8.dp),
            horizontalArrangement = Arrangement.spacedBy(8.dp),
            verticalAlignment = Alignment.Bottom,
        ) {
            OutlinedTextField(
                value = store.composerText,
                onValueChange = { store.composerText = it },
                placeholder = { Text("Message") },
                maxLines = 5,
                shape = RoundedCornerShape(20.dp),
                modifier = Modifier.weight(1f).testTag("member_conversation_composer"),
            )
            IconButton(
                onClick = { scope.launch { store.send() } },
                enabled = store.canSend,
                modifier = Modifier.testTag("member_conversation_send"),
            ) {
                if (store.isSending) {
                    CircularProgressIndicator(Modifier.size(24.dp), strokeWidth = 2.dp)
                } else {
                    Icon(
                        Icons.AutoMirrored.Filled.Send,
                        contentDescription = "Send",
                        tint = if (store.canSend) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }
            }
        }
    }
}
