package com.getjoinery.mail

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.automirrored.outlined.DriveFileMove
import androidx.compose.material.icons.automirrored.outlined.Label
import androidx.compose.material.icons.automirrored.outlined.Forward
import androidx.compose.material.icons.automirrored.outlined.Reply
import androidx.compose.material.icons.automirrored.outlined.ReplyAll
import androidx.compose.material.icons.filled.MoreVert
import androidx.compose.material.icons.filled.Star
import androidx.compose.material.icons.outlined.Archive
import androidx.compose.material.icons.outlined.CheckCircle
import androidx.compose.material.icons.outlined.Delete
import androidx.compose.material.icons.outlined.MarkEmailUnread
import androidx.compose.material.icons.outlined.Report
import androidx.compose.material.icons.outlined.StarBorder
import androidx.compose.material.icons.outlined.Unarchive
import androidx.compose.material3.Button
import androidx.compose.material3.Checkbox
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.RadioButton
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
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
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.launch

/**
 * One conversation, Gmail-style: subject header, message cards (older ones
 * collapsed), triage in the top bar, the Move/Labels control, and a Reply /
 * Reply all / Forward bar pinned to the bottom. Opening the thread marks it
 * read — the same explicit mark_read the web reader performs, so state stays
 * shared.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ThreadDetailScreen(
    store: MailboxStore,
    summary: ThreadSummary,
    onClose: () -> Unit,
) {
    val scope = rememberCoroutineScope()
    var messages by remember { mutableStateOf<List<MailMessage>>(emptyList()) }
    var folderIds by remember { mutableStateOf<Set<Int>>(emptySet()) }
    var expanded by remember { mutableStateOf<Set<Int>>(emptySet()) }
    var isLoading by remember { mutableStateOf(true) }
    var loadFailure by remember { mutableStateOf<String?>(null) }
    var isStarred by remember { mutableStateOf(summary.isStarred) }
    var composeRequest by remember { mutableStateOf<ComposeRequest?>(null) }
    var showFolderSheet by remember { mutableStateOf(false) }
    var menuOpen by remember { mutableStateOf(false) }

    BackHandler(onBack = onClose)

    suspend fun load(markRead: Boolean = true) {
        try {
            val thread = store.api.thread(summary.threadKey, store.selectedAlias)
            messages = thread.messages
            folderIds = thread.folderIds.toSet()
            // Latest message expanded, everything read collapsed; unread
            // messages always start expanded.
            val open = thread.messages.filter { !it.isRead }.map { it.id }.toMutableSet()
            thread.messages.lastOrNull()?.let { open.add(it.id) }
            expanded = open
            isLoading = false
            loadFailure = null
            if (markRead && thread.messages.any { !it.isRead }) {
                try {
                    store.api.threadAction("mark_read", summary.threadKey, store.selectedAlias)
                    store.patch(summary.threadKey) { it.copy(unreadCount = 0) }
                } catch (e: Exception) {
                    // Non-fatal; the list reconciles on the next reload.
                }
            }
        } catch (e: Exception) {
            isLoading = false
            loadFailure = (e as? com.getjoinery.android.JoineryApiError)?.displayMessage
                ?: (e.message ?: "Something went wrong.")
        }
    }

    LaunchedEffect(summary.threadKey) { load() }

    fun act(action: String, thenClose: Boolean) {
        scope.launch {
            store.perform(action, summary)
            if (thenClose) onClose()
        }
    }

    // The mailbox whose folder rail applies to this thread — resolved from the
    // messages' alias so Move/Labels works in the "all mailboxes" list too.
    val threadAlias = messages.firstOrNull { it.aliasId != null }?.aliasId
    val threadMailbox = threadAlias?.let { alias ->
        store.home?.mailboxes?.firstOrNull { it.aliasId == alias }
    }

    Scaffold(
        topBar = {
            TopAppBar(
                title = {},
                navigationIcon = {
                    IconButton(onClick = onClose, modifier = Modifier.testTag("mail_thread_back")) {
                        Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "Back")
                    }
                },
                actions = {
                    if (store.view != MailView.SPAM) {
                        IconButton(
                            onClick = { act(if (summary.isArchived) "unarchive" else "archive", thenClose = true) },
                            modifier = Modifier.testTag("mail_archive"),
                        ) {
                            Icon(
                                if (summary.isArchived) Icons.Outlined.Unarchive else Icons.Outlined.Archive,
                                contentDescription = if (summary.isArchived) "Unarchive" else "Archive",
                            )
                        }
                    }
                    if (threadMailbox != null && threadMailbox.folders.isNotEmpty()) {
                        IconButton(
                            onClick = { showFolderSheet = true },
                            modifier = Modifier.testTag("mail_folders"),
                        ) {
                            Icon(
                                if (threadMailbox.foldersExclusive) Icons.AutoMirrored.Outlined.DriveFileMove
                                else Icons.AutoMirrored.Outlined.Label,
                                contentDescription = if (threadMailbox.foldersExclusive) "Move" else "Labels",
                            )
                        }
                    }
                    IconButton(onClick = { menuOpen = true }, modifier = Modifier.testTag("mail_thread_menu")) {
                        Icon(Icons.Filled.MoreVert, contentDescription = "More actions")
                    }
                    DropdownMenu(expanded = menuOpen, onDismissRequest = { menuOpen = false }) {
                        DropdownMenuItem(
                            text = { Text("Mark unread") },
                            leadingIcon = { Icon(Icons.Outlined.MarkEmailUnread, contentDescription = null) },
                            onClick = { menuOpen = false; act("mark_unread", thenClose = true) },
                        )
                        if (store.view == MailView.SPAM) {
                            DropdownMenuItem(
                                text = { Text("Not spam") },
                                leadingIcon = { Icon(Icons.Outlined.CheckCircle, contentDescription = null) },
                                onClick = { menuOpen = false; act("mark_not_spam", thenClose = true) },
                            )
                        } else {
                            DropdownMenuItem(
                                text = { Text("Report spam") },
                                leadingIcon = { Icon(Icons.Outlined.Report, contentDescription = null) },
                                onClick = { menuOpen = false; act("mark_spam", thenClose = true) },
                            )
                        }
                        DropdownMenuItem(
                            text = { Text("Delete") },
                            leadingIcon = {
                                Icon(Icons.Outlined.Delete, contentDescription = null,
                                    tint = MaterialTheme.colorScheme.error)
                            },
                            onClick = { menuOpen = false; act("delete", thenClose = true) },
                        )
                    }
                },
            )
        },
        bottomBar = {
            val source = messages.lastOrNull()
            if (source != null && store.home?.canCompose == true) {
                Surface(tonalElevation = 3.dp) {
                    Row(
                        Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 10.dp),
                        horizontalArrangement = Arrangement.spacedBy(12.dp),
                    ) {
                        ReplyButton("Reply", Icons.AutoMirrored.Outlined.Reply, "mail_reply", Modifier.weight(1f)) {
                            composeRequest = ComposeRequest(ComposeMode.REPLY, source)
                        }
                        ReplyButton("Reply all", Icons.AutoMirrored.Outlined.ReplyAll, "mail_reply_all", Modifier.weight(1f)) {
                            composeRequest = ComposeRequest(ComposeMode.REPLY_ALL, source)
                        }
                        ReplyButton("Forward", Icons.AutoMirrored.Outlined.Forward, "mail_forward", Modifier.weight(1f)) {
                            composeRequest = ComposeRequest(ComposeMode.FORWARD, source)
                        }
                    }
                }
            }
        },
    ) { padding ->
        when {
            isLoading ->
                Box(Modifier.fillMaxSize().padding(padding), contentAlignment = Alignment.Center) {
                    CircularProgressIndicator(Modifier.testTag("mail_thread_loading"))
                }
            loadFailure != null ->
                Box(Modifier.fillMaxSize().padding(padding).padding(24.dp), contentAlignment = Alignment.Center) {
                    Column(
                        horizontalAlignment = Alignment.CenterHorizontally,
                        verticalArrangement = Arrangement.spacedBy(12.dp),
                    ) {
                        Text(loadFailure!!, color = MaterialTheme.colorScheme.onSurfaceVariant)
                        Button(onClick = { scope.launch { load() } }) { Text("Try Again") }
                    }
                }
            else ->
                Column(
                    Modifier.fillMaxSize().padding(padding).verticalScroll(rememberScrollState()),
                ) {
                    Row(
                        Modifier.fillMaxWidth().padding(start = 16.dp, end = 8.dp, top = 12.dp, bottom = 4.dp),
                        verticalAlignment = Alignment.Top,
                    ) {
                        Text(
                            summary.subject.ifEmpty { "(no subject)" },
                            style = MaterialTheme.typography.titleLarge,
                            fontWeight = FontWeight.SemiBold,
                            modifier = Modifier.weight(1f).testTag("mail_thread_subject"),
                        )
                        IconButton(onClick = {
                            val action = if (isStarred) "unstar" else "star"
                            isStarred = !isStarred
                            scope.launch { store.perform(action, summary) }
                        }) {
                            Icon(
                                if (isStarred) Icons.Filled.Star else Icons.Outlined.StarBorder,
                                contentDescription = if (isStarred) "Unstar" else "Star",
                                tint = if (isStarred) Color(0xFFF9A825)
                                else MaterialTheme.colorScheme.onSurfaceVariant,
                            )
                        }
                    }
                    messages.forEach { message ->
                        MessageCard(
                            message = message,
                            isExpanded = expanded.contains(message.id),
                            onToggle = {
                                expanded = if (expanded.contains(message.id)) {
                                    expanded - message.id
                                } else {
                                    expanded + message.id
                                }
                            },
                        )
                    }
                }
        }
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
                scope.launch { load(markRead = false) }
            },
        )
    }

    if (showFolderSheet && threadMailbox != null && threadAlias != null) {
        FolderSheet(
            mailbox = threadMailbox,
            currentIds = folderIds,
            onDismiss = { showFolderSheet = false },
            onMove = { folder ->
                // Exclusive feed: choosing a folder relocates the thread.
                scope.launch {
                    try {
                        store.api.threadAction(
                            "set_membership", summary.threadKey, store.selectedAlias,
                            folderId = folder.id, present = true,
                        )
                        showFolderSheet = false
                        store.reload(refreshMailboxes = true)
                        onClose()
                    } catch (e: Exception) {
                        showFolderSheet = false
                    }
                }
            },
            onToggle = { folder, present ->
                // Non-exclusive (Gmail): toggling adds/removes the label.
                scope.launch {
                    try {
                        store.api.threadAction(
                            "set_membership", summary.threadKey, store.selectedAlias,
                            folderId = folder.id, present = present,
                        )
                        folderIds = if (present) folderIds + folder.id else folderIds - folder.id
                        store.reload(refreshMailboxes = true)
                    } catch (e: Exception) {
                        // Leave the sheet state as-is; the checkbox reflects folderIds.
                    }
                }
            },
            onCreate = { name ->
                scope.launch {
                    try {
                        val folder = store.api.createFolder(name, summary.threadKey, threadAlias)
                        if (folder != null) {
                            folderIds = folderIds + folder.id
                            store.reload(refreshMailboxes = true)
                            if (threadMailbox.foldersExclusive) {
                                showFolderSheet = false
                                onClose()
                            }
                        }
                    } catch (e: Exception) {
                        // Creation failure leaves the sheet open for a retry.
                    }
                }
            },
        )
    }
}

@Composable
private fun ReplyButton(
    label: String,
    icon: androidx.compose.ui.graphics.vector.ImageVector,
    tag: String,
    modifier: Modifier = Modifier,
    onClick: () -> Unit,
) {
    OutlinedButton(
        onClick = onClick,
        modifier = modifier.testTag(tag),
        contentPadding = androidx.compose.foundation.layout.PaddingValues(horizontal = 10.dp, vertical = 8.dp),
    ) {
        Icon(icon, contentDescription = null, modifier = Modifier.padding(end = 4.dp).size(18.dp))
        Text(label, maxLines = 1, style = MaterialTheme.typography.labelLarge)
    }
}

/**
 * The Move/Labels picker (the web reader's `buildFolderControl` as a sheet).
 * Exclusive feeds get a single-pick "Move to" (radio rows — choosing a folder
 * relocates the thread); non-exclusive feeds (Gmail) get "Labels" with a
 * checkbox per folder. Both end with a create row; the sync push creates the
 * folder on the source and files the thread into it.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun FolderSheet(
    mailbox: Mailbox,
    currentIds: Set<Int>,
    onDismiss: () -> Unit,
    onMove: (MailFolder) -> Unit,
    onToggle: (MailFolder, Boolean) -> Unit,
    onCreate: (String) -> Unit,
) {
    var newName by remember { mutableStateOf("") }

    ModalBottomSheet(onDismissRequest = onDismiss, modifier = Modifier.testTag("mail_folder_sheet")) {
        Column(Modifier.fillMaxWidth().padding(bottom = 24.dp)) {
            Text(
                if (mailbox.foldersExclusive) "Move to" else "Labels",
                style = MaterialTheme.typography.titleMedium,
                modifier = Modifier.padding(horizontal = 24.dp, vertical = 8.dp),
            )
            mailbox.folders.forEach { folder ->
                val isMember = currentIds.contains(folder.id)
                Row(
                    Modifier.fillMaxWidth().padding(horizontal = 12.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    if (mailbox.foldersExclusive) {
                        RadioButton(
                            selected = isMember,
                            onClick = { if (!isMember) onMove(folder) },
                        )
                    } else {
                        Checkbox(
                            checked = isMember,
                            onCheckedChange = { checked -> onToggle(folder, checked) },
                        )
                    }
                    Text(folder.name, modifier = Modifier.padding(start = 4.dp))
                }
            }
            HorizontalDivider(Modifier.padding(vertical = 8.dp))
            Row(
                Modifier.fillMaxWidth().padding(horizontal = 24.dp),
                horizontalArrangement = Arrangement.spacedBy(8.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                OutlinedTextField(
                    value = newName,
                    onValueChange = { newName = it },
                    placeholder = {
                        Text(if (mailbox.foldersExclusive) "New folder…" else "New label…")
                    },
                    singleLine = true,
                    modifier = Modifier.weight(1f).testTag("mail_folder_new"),
                )
                Button(
                    onClick = {
                        val name = newName.trim()
                        if (name.isNotEmpty()) {
                            newName = ""
                            onCreate(name)
                        }
                    },
                    enabled = newName.trim().isNotEmpty(),
                    modifier = Modifier.testTag("mail_folder_create"),
                ) { Text("Add") }
            }
        }
    }
}
