package com.getjoinery.memberkit

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
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.Delete
import androidx.compose.material.icons.outlined.Notifications
import androidx.compose.material.icons.outlined.NotificationsOff
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.SwipeToDismissBox
import androidx.compose.material3.SwipeToDismissBoxValue
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
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
import androidx.compose.ui.platform.LocalLifecycleOwner
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleEventObserver
import com.getjoinery.android.ApiClient
import kotlinx.coroutines.launch

/**
 * The conversation inbox: a paginated list with mute/unmute (swipe right) and
 * delete (swipe left), opening into a threaded conversation. No
 * new-conversation entry point — the compose/member-picker is parked on a
 * product decision on both platforms.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ConversationsScreen(client: ApiClient, onBack: (() -> Unit)? = null) {
    val api = remember { ConversationApi(client) }
    val store = remember { ConversationListStore(api) }
    val scope = rememberCoroutineScope()
    var openThread by remember { mutableStateOf<ConversationRow?>(null) }
    var pendingDelete by remember { mutableStateOf<ConversationRow?>(null) }

    LaunchedEffect(Unit) {
        if (store.phase is MemberPhase.Loading) store.initialLoad()
    }

    // Re-read on foreground so a just-read conversation's unread badge and
    // bumped preview show up; skips the initial resume and while a thread is open.
    val lifecycleOwner = LocalLifecycleOwner.current
    DisposableEffect(lifecycleOwner) {
        val observer = LifecycleEventObserver { _, event ->
            if (event == Lifecycle.Event.ON_RESUME && openThread == null && store.phase !is MemberPhase.Loading) {
                scope.launch { store.reload() }
            }
        }
        lifecycleOwner.lifecycle.addObserver(observer)
        onDispose { lifecycleOwner.lifecycle.removeObserver(observer) }
    }

    val opened = openThread
    if (opened != null) {
        ConversationThreadView(
            api = api,
            origin = ThreadOrigin.Conversation(opened.conversationId, opened.otherDisplayName),
            onClose = {
                openThread = null
                scope.launch { store.reload() }
            },
        )
        return
    }

    Scaffold(topBar = { MemberTopBar("Messages", onBack) }) { padding ->
        when (val phase = store.phase) {
            is MemberPhase.Loading ->
                Box(Modifier.fillMaxSize().padding(padding), contentAlignment = Alignment.Center) {
                    CircularProgressIndicator(Modifier.testTag("member_conversations_loading"))
                }
            is MemberPhase.Failed ->
                RetryBox(phase.message, "member_conversations_error", "member_conversations_retry", Modifier.padding(padding)) {
                    scope.launch { store.initialLoad() }
                }
            is MemberPhase.Loaded ->
                MemberPullRefresh(onRefresh = { store.reload() }, Modifier.padding(padding)) {
                LazyColumn(Modifier.fillMaxSize().testTag("member_conversations_list")) {
                    if (store.conversations.isEmpty()) {
                        item {
                            Text(
                                "No conversations yet.",
                                color = MaterialTheme.colorScheme.onSurfaceVariant,
                                modifier = Modifier.padding(16.dp).testTag("member_conversations_empty"),
                            )
                        }
                    }
                    items(store.conversations.size, key = { store.conversations[it].conversationId }) { index ->
                        val conversation = store.conversations[index]
                        SwipeableConversationRow(
                            conversation = conversation,
                            onOpen = { openThread = conversation },
                            onToggleMute = { scope.launch { store.toggleMute(it) } },
                            onDeleteRequest = { pendingDelete = it },
                        )
                        HorizontalDivider(color = MaterialTheme.colorScheme.outlineVariant)
                        if (index == store.conversations.lastIndex) {
                            LaunchedEffect(conversation.conversationId) { store.loadMore() }
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
                }
        }
    }

    // Swipe-to-delete arms this confirmation rather than deleting outright —
    // deletion is destructive with no undo, so it matches the thread view's
    // confirm (mute stays immediate: non-destructive and self-inverse).
    pendingDelete?.let { conversation ->
        AlertDialog(
            onDismissRequest = { pendingDelete = null },
            title = { Text("Delete this conversation?") },
            confirmButton = {
                TextButton(
                    onClick = {
                        pendingDelete = null
                        scope.launch { store.delete(conversation) }
                    },
                    modifier = Modifier.testTag("member_conversation_delete_confirm"),
                ) { Text("Delete", color = MaterialTheme.colorScheme.error) }
            },
            dismissButton = { TextButton(onClick = { pendingDelete = null }) { Text("Cancel") } },
        )
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun SwipeableConversationRow(
    conversation: ConversationRow,
    onOpen: () -> Unit,
    onToggleMute: (ConversationRow) -> Unit,
    onDeleteRequest: (ConversationRow) -> Unit,
) {
    val current = rememberUpdatedState(conversation)
    val muteCallback = rememberUpdatedState(onToggleMute)
    val deleteCallback = rememberUpdatedState(onDeleteRequest)
    val dismissState = rememberSwipeToDismissBoxState(
        confirmValueChange = { value ->
            when (value) {
                SwipeToDismissBoxValue.StartToEnd -> muteCallback.value(current.value)
                // Delete arms a confirmation; the row snaps back either way.
                SwipeToDismissBoxValue.EndToStart -> deleteCallback.value(current.value)
                else -> {}
            }
            false // Snap back; mute patches locally, delete waits on the dialog.
        },
    )

    SwipeToDismissBox(
        state = dismissState,
        backgroundContent = {
            val (color, icon, alignment) = when (dismissState.dismissDirection) {
                SwipeToDismissBoxValue.StartToEnd -> Triple(
                    Color(0xFFF57D00),
                    if (conversation.muted) Icons.Outlined.Notifications else Icons.Outlined.NotificationsOff,
                    Alignment.CenterStart,
                )
                SwipeToDismissBoxValue.EndToStart -> Triple(Color(0xFFDB3335), Icons.Outlined.Delete, Alignment.CenterEnd)
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
        ConversationRowView(conversation, onOpen)
    }
}

/** One inbox row: bold when unread, a muted glyph when muted, preview text. */
@Composable
private fun ConversationRowView(conversation: ConversationRow, onOpen: () -> Unit) {
    Row(
        Modifier
            .fillMaxWidth()
            .background(MaterialTheme.colorScheme.surface)
            .clickable(onClick = onOpen)
            .padding(horizontal = 16.dp, vertical = 12.dp)
            .testTag("member_conversation_row"),
        horizontalArrangement = Arrangement.spacedBy(10.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(
            Modifier.size(8.dp).background(
                if (conversation.unread) MaterialTheme.colorScheme.primary else Color.Transparent,
                CircleShape,
            ),
        )
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(
                    conversation.otherDisplayName,
                    style = MaterialTheme.typography.bodyMedium,
                    fontWeight = if (conversation.unread) FontWeight.SemiBold else FontWeight.Normal,
                    modifier = Modifier.weight(1f),
                )
                if (conversation.muted) {
                    Icon(
                        Icons.Outlined.NotificationsOff,
                        contentDescription = "Muted",
                        tint = MaterialTheme.colorScheme.onSurfaceVariant,
                        modifier = Modifier.size(14.dp),
                    )
                    Spacer(Modifier.size(6.dp))
                }
                Text(
                    MemberDisplay.listStamp(conversation.lastMessageTime),
                    style = MaterialTheme.typography.labelSmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
            Text(
                conversation.preview,
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                maxLines = 1,
            )
        }
    }
}
