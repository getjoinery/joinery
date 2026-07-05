package com.getjoinery.aichat

import android.content.Context
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.net.Uri
import android.provider.OpenableColumns
import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.tween
import androidx.compose.foundation.ExperimentalFoundationApi
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.combinedClickable
import androidx.compose.foundation.horizontalScroll
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
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.selection.SelectionContainer
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.automirrored.filled.Send
import androidx.compose.material.icons.outlined.AddCircle
import androidx.compose.material.icons.outlined.AutoAwesome
import androidx.compose.material.icons.outlined.Close
import androidx.compose.material.icons.outlined.Description
import androidx.compose.material.icons.outlined.Image
import androidx.compose.material.icons.outlined.Settings
import androidx.compose.material.icons.outlined.Warning
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.produceState
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import java.io.ByteArrayOutputStream
import java.net.URL
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

/**
 * One conversation: the turns in a scroll with a composer pinned to the
 * bottom. A running turn streams its answer via the store's poll loop; a
 * turn that proposes an action shows a Confirm / Cancel card.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun ChatThreadView(
    api: ChatApi,
    conversationId: Int?,
    title: String,
    onClose: () -> Unit,
) {
    val scope = rememberCoroutineScope()
    val store = remember { ChatThreadStore(api, conversationId, title, scope) }
    var showSettings by remember { mutableStateOf(false) }

    BackHandler(onBack = onClose)
    DisposableEffect(Unit) {
        onDispose { store.stopPolling() }
    }
    LaunchedEffect(Unit) {
        if (store.phase is ChatThreadStore.Phase.Loading) store.load()
    }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text(store.title, maxLines = 1, overflow = TextOverflow.Ellipsis) },
                navigationIcon = {
                    IconButton(onClick = onClose, modifier = Modifier.testTag("chat_back")) {
                        Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "Back")
                    }
                },
                actions = {
                    IconButton(
                        onClick = { showSettings = true },
                        modifier = Modifier.testTag("chat_settings"),
                    ) {
                        Icon(Icons.Outlined.Settings, contentDescription = "Chat settings")
                    }
                },
            )
        },
        bottomBar = { Composer(store) },
    ) { padding ->
        when (val phase = store.phase) {
            is ChatThreadStore.Phase.Loading ->
                Box(Modifier.fillMaxSize().padding(padding), contentAlignment = Alignment.Center) {
                    CircularProgressIndicator(Modifier.testTag("chat_thread_loading"))
                }
            is ChatThreadStore.Phase.Failed ->
                Box(Modifier.fillMaxSize().padding(padding).padding(24.dp), contentAlignment = Alignment.Center) {
                    Column(
                        horizontalAlignment = Alignment.CenterHorizontally,
                        verticalArrangement = Arrangement.spacedBy(12.dp),
                    ) {
                        Text(
                            phase.message,
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                            textAlign = TextAlign.Center,
                            modifier = Modifier.testTag("chat_thread_error"),
                        )
                        Button(onClick = { scope.launch { store.load() } }) { Text("Try Again") }
                    }
                }
            is ChatThreadStore.Phase.Loaded ->
                Transcript(store, Modifier.padding(padding))
        }
    }

    if (showSettings) {
        ChatSettingsSheet(store = store, onDismiss = { showSettings = false })
    }
}

@Composable
private fun Transcript(store: ChatThreadStore, modifier: Modifier = Modifier) {
    val scope = rememberCoroutineScope()
    val listState = rememberLazyListState()
    val lastContentLength = store.messages.lastOrNull()?.content?.length ?: 0

    // Pin the view to the newest content as rows arrive and stream.
    LaunchedEffect(store.messages.size, lastContentLength) {
        if (store.messages.isNotEmpty()) {
            listState.animateScrollToItem(store.messages.size - 1)
        }
    }

    LazyColumn(
        state = listState,
        modifier = modifier.fillMaxSize().testTag("chat_transcript"),
        verticalArrangement = Arrangement.spacedBy(14.dp),
        contentPadding = androidx.compose.foundation.layout.PaddingValues(
            start = 14.dp, end = 14.dp, top = 12.dp, bottom = 12.dp,
        ),
    ) {
        if (store.messages.isEmpty()) {
            item {
                Column(
                    Modifier.fillMaxWidth().padding(vertical = 80.dp),
                    horizontalAlignment = Alignment.CenterHorizontally,
                    verticalArrangement = Arrangement.spacedBy(8.dp),
                ) {
                    Icon(
                        Icons.Outlined.AutoAwesome,
                        contentDescription = null,
                        tint = MaterialTheme.colorScheme.onSurfaceVariant,
                        modifier = Modifier.size(40.dp),
                    )
                    Text(
                        "Ask the assistant anything.",
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                        modifier = Modifier.testTag("chat_thread_empty"),
                    )
                }
            }
        }
        items(store.messages.size, key = { store.messages[it].id }) { index ->
            val message = store.messages[index]
            MessageRow(
                message = message,
                baseUrl = store.baseUrl,
                onDecision = { decision ->
                    scope.launch { store.resolvePending(message.id, decision) }
                },
                onDelete = {
                    scope.launch { store.deleteTurn(message) }
                },
            )
        }
    }
}

// MARK: - Composer

@Composable
private fun Composer(store: ChatThreadStore) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var attachMenuOpen by remember { mutableStateOf(false) }

    fun addPicked(uris: List<Uri>) {
        scope.launch {
            for (uri in uris) {
                withContext(Dispatchers.IO) { readChatAttachment(context, uri) }
                    ?.let { store.addAttachment(it) }
            }
        }
    }

    val photoPicker = rememberLauncherForActivityResult(
        ActivityResultContracts.GetMultipleContents(),
    ) { uris -> addPicked(uris) }
    val filePicker = rememberLauncherForActivityResult(
        ActivityResultContracts.OpenMultipleDocuments(),
    ) { uris -> addPicked(uris) }

    Surface(tonalElevation = 3.dp) {
        Column(Modifier.fillMaxWidth().padding(vertical = 6.dp)) {
            if (store.usageLabel.isNotEmpty()) {
                Text(
                    store.usageLabel,
                    style = MaterialTheme.typography.labelSmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                    textAlign = TextAlign.Center,
                    modifier = Modifier.fillMaxWidth(),
                )
            }
            if (store.attachmentNotice.isNotEmpty()) {
                Row(
                    Modifier
                        .fillMaxWidth()
                        .padding(horizontal = 12.dp, vertical = 4.dp)
                        .background(
                            MaterialTheme.colorScheme.errorContainer.copy(alpha = 0.5f),
                            RoundedCornerShape(8.dp),
                        )
                        .padding(horizontal = 10.dp, vertical = 7.dp)
                        .testTag("chat_attachment_notice"),
                    horizontalArrangement = Arrangement.spacedBy(8.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Icon(
                        Icons.Outlined.Warning,
                        contentDescription = null,
                        tint = MaterialTheme.colorScheme.error,
                        modifier = Modifier.size(16.dp),
                    )
                    Text(
                        store.attachmentNotice,
                        style = MaterialTheme.typography.labelMedium,
                        modifier = Modifier.weight(1f),
                    )
                    IconButton(
                        onClick = { store.dismissAttachmentNotice() },
                        modifier = Modifier.size(24.dp),
                    ) {
                        Icon(Icons.Outlined.Close, contentDescription = "Dismiss", modifier = Modifier.size(14.dp))
                    }
                }
            }
            if (store.pendingAttachments.isNotEmpty()) {
                Row(
                    Modifier
                        .fillMaxWidth()
                        .horizontalScroll(rememberScrollState())
                        .padding(horizontal = 12.dp, vertical = 4.dp)
                        .testTag("chat_attachment_strip"),
                    horizontalArrangement = Arrangement.spacedBy(8.dp),
                ) {
                    store.pendingAttachments.forEach { att ->
                        Surface(shape = CircleShape, color = MaterialTheme.colorScheme.surfaceVariant) {
                            Row(
                                Modifier.padding(start = 10.dp, end = 4.dp, top = 4.dp, bottom = 4.dp),
                                horizontalArrangement = Arrangement.spacedBy(6.dp),
                                verticalAlignment = Alignment.CenterVertically,
                            ) {
                                Icon(
                                    if (att.mimeType.startsWith("image/")) Icons.Outlined.Image
                                    else Icons.Outlined.Description,
                                    contentDescription = null,
                                    modifier = Modifier.size(14.dp),
                                    tint = MaterialTheme.colorScheme.onSurfaceVariant,
                                )
                                Text(att.filename, style = MaterialTheme.typography.labelMedium, maxLines = 1)
                                IconButton(
                                    onClick = { store.removeAttachment(att.id) },
                                    modifier = Modifier.size(20.dp).testTag("chat_attachment_remove"),
                                ) {
                                    Icon(Icons.Outlined.Close, contentDescription = "Remove", modifier = Modifier.size(12.dp))
                                }
                            }
                        }
                    }
                }
            }
            Row(
                Modifier.fillMaxWidth().padding(horizontal = 12.dp, vertical = 4.dp),
                horizontalArrangement = Arrangement.spacedBy(8.dp),
                verticalAlignment = Alignment.Bottom,
            ) {
                Box {
                    IconButton(
                        onClick = { attachMenuOpen = true },
                        enabled = !store.isSending && !store.isTurnRunning,
                        modifier = Modifier.testTag("chat_attach"),
                    ) {
                        Icon(Icons.Outlined.AddCircle, contentDescription = "Attach")
                    }
                    DropdownMenu(expanded = attachMenuOpen, onDismissRequest = { attachMenuOpen = false }) {
                        DropdownMenuItem(
                            text = { Text("Photos") },
                            leadingIcon = { Icon(Icons.Outlined.Image, contentDescription = null) },
                            onClick = { attachMenuOpen = false; photoPicker.launch("image/*") },
                        )
                        DropdownMenuItem(
                            text = { Text("Files") },
                            leadingIcon = { Icon(Icons.Outlined.Description, contentDescription = null) },
                            onClick = {
                                attachMenuOpen = false
                                // The server's non-image allowed set (it
                                // re-detects and is the authority; this is a
                                // first-pass filter).
                                filePicker.launch(
                                    arrayOf(
                                        "application/pdf", "text/plain", "text/csv",
                                        "application/json", "text/markdown", "image/*",
                                    ),
                                )
                            },
                        )
                    }
                }
                OutlinedTextField(
                    value = store.composerText,
                    onValueChange = { store.composerText = it },
                    placeholder = { Text("Message") },
                    maxLines = 5,
                    shape = RoundedCornerShape(20.dp),
                    modifier = Modifier.weight(1f).testTag("chat_composer"),
                )
                IconButton(
                    onClick = { scope.launch { store.send() } },
                    enabled = store.canSend,
                    modifier = Modifier.testTag("chat_send"),
                ) {
                    if (store.isSending || store.isTurnRunning) {
                        CircularProgressIndicator(Modifier.size(24.dp), strokeWidth = 2.dp)
                    } else {
                        Icon(
                            Icons.AutoMirrored.Filled.Send,
                            contentDescription = "Send",
                            tint = if (store.canSend) MaterialTheme.colorScheme.primary
                            else MaterialTheme.colorScheme.onSurfaceVariant,
                        )
                    }
                }
            }
        }
    }
}

/** "2m 40s" from a seconds count, for the live activity line. */
internal fun formatElapsed(seconds: Int): String {
    val s = maxOf(0, seconds)
    if (s < 60) return "${s}s"
    return "${s / 60}m ${s % 60}s"
}

/** Image byte types the server accepts as-is; anything else that decodes as
 *  an image (notably HEIC) is transcoded to JPEG so the server's
 *  byte-detected type lands in its allowed set. */
private val directImageTypes = setOf("image/png", "image/jpeg", "image/gif", "image/webp")

private fun readChatAttachment(context: Context, uri: Uri): ChatOutgoingAttachment? {
    return try {
        val data = context.contentResolver.openInputStream(uri)?.use { it.readBytes() } ?: return null
        var name = uri.lastPathSegment ?: "attachment"
        context.contentResolver.query(uri, null, null, null, null)?.use { cursor ->
            val index = cursor.getColumnIndex(OpenableColumns.DISPLAY_NAME)
            if (index >= 0 && cursor.moveToFirst()) {
                cursor.getString(index)?.let { name = it }
            }
        }
        val mime = context.contentResolver.getType(uri) ?: "application/octet-stream"
        if (mime.startsWith("image/") && !directImageTypes.contains(mime)) {
            val bitmap = BitmapFactory.decodeByteArray(data, 0, data.size) ?: return null
            val out = ByteArrayOutputStream()
            bitmap.compress(Bitmap.CompressFormat.JPEG, 90, out)
            return ChatOutgoingAttachment(filename = "photo.jpg", mimeType = "image/jpeg", data = out.toByteArray())
        }
        ChatOutgoingAttachment(filename = name, mimeType = mime, data = data)
    } catch (e: Exception) {
        null
    }
}

// MARK: - Message rows

/** One turn. The user's on the right; the assistant on the left with
 *  markdown, an optional tool trace, a running indicator, an error, or a
 *  confirm card. Long-press deletes the turn. */
@OptIn(ExperimentalFoundationApi::class)
@Composable
private fun MessageRow(
    message: ChatMessage,
    baseUrl: String,
    onDecision: (String) -> Unit,
    onDelete: () -> Unit,
) {
    var menuOpen by remember { mutableStateOf(false) }

    Box {
        if (message.role == ChatRole.USER) {
            Row(
                Modifier
                    .fillMaxWidth()
                    .combinedClickable(onClick = {}, onLongClick = { menuOpen = true })
                    .testTag("chat_user_message"),
            ) {
                Spacer(Modifier.weight(1f, fill = true).sizeIn(minWidth = 40.dp))
                Column(horizontalAlignment = Alignment.End, verticalArrangement = Arrangement.spacedBy(6.dp)) {
                    if (message.attachments.isNotEmpty()) {
                        AttachmentsView(message.attachments, baseUrl)
                    }
                    if (message.content.isNotEmpty()) {
                        Surface(
                            shape = RoundedCornerShape(18.dp),
                            color = MaterialTheme.colorScheme.primary,
                        ) {
                            Text(
                                message.content,
                                color = MaterialTheme.colorScheme.onPrimary,
                                modifier = Modifier.padding(horizontal = 14.dp, vertical = 9.dp),
                            )
                        }
                    }
                }
            }
        } else {
            Column(
                Modifier
                    .fillMaxWidth()
                    .padding(end = 24.dp)
                    .combinedClickable(onClick = {}, onLongClick = { menuOpen = true })
                    .testTag("chat_assistant_message"),
                verticalArrangement = Arrangement.spacedBy(8.dp),
            ) {
                when {
                    message.status == ChatStatus.FAILED -> Row(
                        horizontalArrangement = Arrangement.spacedBy(6.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Icon(
                            Icons.Outlined.Warning,
                            contentDescription = null,
                            tint = MaterialTheme.colorScheme.error,
                            modifier = Modifier.size(16.dp),
                        )
                        Text(
                            message.error.ifEmpty { "The assistant could not complete this turn." },
                            color = MaterialTheme.colorScheme.error,
                            style = MaterialTheme.typography.bodyMedium,
                        )
                    }
                    message.content.isEmpty() && message.status == ChatStatus.RUNNING ->
                        TypingIndicator()
                    else ->
                        SelectionContainer { MarkdownText(message.content) }
                }
                if (message.toolCalls.isNotEmpty()) {
                    ToolTrace(message.toolCalls)
                }
                message.pendingAction?.let { pending ->
                    ConfirmCard(pending.description, onDecision)
                }
                // The runner's live stage line while the turn works ("Waiting
                // for glm-5p2… · 2m 40s") — the quiet stretch before the first
                // token is legible instead of an anonymous indicator.
                if (message.status == ChatStatus.RUNNING && message.activity.isNotEmpty()) {
                    val elapsed = message.runningSeconds?.let { " · ${formatElapsed(it)}" } ?: ""
                    Text(
                        message.activity + elapsed,
                        style = MaterialTheme.typography.labelSmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                        modifier = Modifier.testTag("chat_activity"),
                    )
                }
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
                    if (message.status == ChatStatus.RUNNING && message.content.isNotEmpty()) {
                        CircularProgressIndicator(Modifier.size(12.dp), strokeWidth = 1.5.dp)
                    }
                    if (message.costLabel.isNotEmpty()) {
                        Text(
                            message.costLabel,
                            style = MaterialTheme.typography.labelSmall,
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                        )
                    }
                }
            }
        }
        DropdownMenu(expanded = menuOpen, onDismissRequest = { menuOpen = false }) {
            DropdownMenuItem(
                text = { Text("Delete") },
                onClick = { menuOpen = false; onDelete() },
            )
        }
    }
}

@OptIn(ExperimentalFoundationApi::class)
@Composable
private fun ToolTrace(toolCalls: List<ChatToolCall>) {
    var open by remember { mutableStateOf(false) }
    Column(verticalArrangement = Arrangement.spacedBy(3.dp)) {
        Text(
            "${toolCalls.size} tool call${if (toolCalls.size == 1) "" else "s"}",
            style = MaterialTheme.typography.labelSmall,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
            modifier = Modifier.combinedClickable(onClick = { open = !open }, onLongClick = {}),
        )
        if (open) {
            toolCalls.forEach { call ->
                Row(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalAlignment = Alignment.CenterVertically) {
                    Icon(
                        if (call.isError) Icons.Outlined.Close else Icons.Outlined.Description,
                        contentDescription = null,
                        tint = if (call.isError) MaterialTheme.colorScheme.error
                        else MaterialTheme.colorScheme.onSurfaceVariant,
                        modifier = Modifier.size(12.dp),
                    )
                    Text(call.name, style = MaterialTheme.typography.labelSmall)
                    call.durationMs?.let {
                        Text(
                            "· ${it}ms",
                            style = MaterialTheme.typography.labelSmall,
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                        )
                    }
                }
            }
        }
    }
}

/** The assistant's "confirm before I do this" card for a held mutating action. */
@Composable
private fun ConfirmCard(description: String, onDecision: (String) -> Unit) {
    Surface(
        shape = RoundedCornerShape(12.dp),
        color = MaterialTheme.colorScheme.surfaceVariant,
        modifier = Modifier
            .fillMaxWidth()
            .border(1.dp, MaterialTheme.colorScheme.primary.copy(alpha = 0.4f), RoundedCornerShape(12.dp))
            .testTag("chat_confirm_card"),
    ) {
        Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Text(description, style = MaterialTheme.typography.bodyMedium)
            Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                Button(
                    onClick = { onDecision("confirm") },
                    modifier = Modifier.testTag("chat_confirm_yes"),
                ) { Text("Confirm") }
                OutlinedButton(
                    onClick = { onDecision("cancel") },
                    modifier = Modifier.testTag("chat_confirm_no"),
                ) { Text("Cancel") }
            }
        }
    }
}

/** A three-dot "thinking" indicator shown while a turn runs before any text
 *  has streamed back. */
@Composable
private fun TypingIndicator() {
    val transition = rememberInfiniteTransition(label = "typing")
    val phase by transition.animateFloat(
        initialValue = 0f,
        targetValue = 3f,
        animationSpec = infiniteRepeatable(animation = tween(900)),
        label = "typing_phase",
    )
    Row(
        horizontalArrangement = Arrangement.spacedBy(5.dp),
        modifier = Modifier.testTag("chat_typing"),
    ) {
        repeat(3) { i ->
            Box(
                Modifier
                    .size(7.dp)
                    .background(
                        MaterialTheme.colorScheme.onSurfaceVariant.copy(
                            alpha = if (phase.toInt() == i) 1f else 0.35f,
                        ),
                        CircleShape,
                    ),
            )
        }
    }
}

// MARK: - Attachments on turns

/** The files on a turn: images as thumbnails loaded from their signed URL,
 *  everything else as a labeled file chip. A thumbnail that fails to load
 *  (an expired signed URL after the 5-minute TTL) falls back to a chip. */
@Composable
private fun AttachmentsView(attachments: List<ChatAttachment>, baseUrl: String) {
    Column(horizontalAlignment = Alignment.End, verticalArrangement = Arrangement.spacedBy(6.dp)) {
        attachments.forEach { att ->
            if (att.isImage) {
                RemoteThumbnail(att, baseUrl)
            } else {
                FileChip(att)
            }
        }
    }
}

@Composable
private fun RemoteThumbnail(att: ChatAttachment, baseUrl: String) {
    val resolved = if (att.imageUrl.startsWith("http")) att.imageUrl else baseUrl + att.imageUrl
    val bitmap by produceState<Bitmap?>(initialValue = null, resolved) {
        value = withContext(Dispatchers.IO) {
            try {
                URL(resolved).openStream().use { BitmapFactory.decodeStream(it) }
            } catch (e: Exception) {
                null
            }
        }
    }
    val loaded = bitmap
    if (loaded != null) {
        Image(
            bitmap = loaded.asImageBitmap(),
            contentDescription = att.name,
            contentScale = ContentScale.Crop,
            modifier = Modifier
                .sizeIn(maxWidth = 200.dp, maxHeight = 200.dp)
                .clip(RoundedCornerShape(12.dp))
                .testTag("chat_attachment_image"),
        )
    } else {
        FileChip(att)
    }
}

@Composable
private fun FileChip(att: ChatAttachment) {
    Surface(shape = CircleShape, color = MaterialTheme.colorScheme.surfaceVariant) {
        Row(
            Modifier.padding(horizontal = 10.dp, vertical = 7.dp).testTag("chat_attachment_chip"),
            horizontalArrangement = Arrangement.spacedBy(6.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Icon(
                when (att.category) {
                    "image" -> Icons.Outlined.Image
                    else -> Icons.Outlined.Description
                },
                contentDescription = null,
                modifier = Modifier.size(14.dp),
                tint = MaterialTheme.colorScheme.onSurfaceVariant,
            )
            Text(att.name, style = MaterialTheme.typography.labelMedium, maxLines = 1)
        }
    }
}

// MARK: - Markdown

/**
 * Renders assistant markdown block by block: headings, bullet rows, and
 * horizontal rules become native layout; every other line renders with
 * inline formatting (bold/italic/code). Enough for chat replies without
 * pulling in a full CommonMark renderer.
 */
@Composable
internal fun MarkdownText(raw: String) {
    Column(verticalArrangement = Arrangement.spacedBy(5.dp)) {
        raw.split("\n").forEach { line ->
            val trimmed = line.trim()
            when {
                trimmed.isEmpty() -> Spacer(Modifier.size(2.dp))
                trimmed == "---" || trimmed == "***" || trimmed == "___" ->
                    androidx.compose.material3.HorizontalDivider(Modifier.padding(vertical = 2.dp))
                trimmed.startsWith("### ") ->
                    Text(
                        inlineMarkdown(trimmed.removePrefix("### ")),
                        style = MaterialTheme.typography.titleSmall,
                        modifier = Modifier.padding(top = 2.dp),
                    )
                trimmed.startsWith("## ") ->
                    Text(
                        inlineMarkdown(trimmed.removePrefix("## ")),
                        style = MaterialTheme.typography.titleMedium,
                        modifier = Modifier.padding(top = 2.dp),
                    )
                trimmed.startsWith("# ") ->
                    Text(
                        inlineMarkdown(trimmed.removePrefix("# ")),
                        style = MaterialTheme.typography.titleLarge,
                        modifier = Modifier.padding(top = 2.dp),
                    )
                trimmed.startsWith("- ") || trimmed.startsWith("* ") || trimmed.startsWith("+ ") ->
                    Row(horizontalArrangement = Arrangement.spacedBy(7.dp), verticalAlignment = Alignment.Top) {
                        Text("•", color = MaterialTheme.colorScheme.onSurfaceVariant)
                        Text(inlineMarkdown(trimmed.substring(2)), style = MaterialTheme.typography.bodyLarge)
                    }
                else ->
                    Text(inlineMarkdown(line), style = MaterialTheme.typography.bodyLarge)
            }
        }
    }
}

/** Inline markdown → AnnotatedString: `**bold**`, `*italic*`, `` `code` ``,
 *  and `[label](url)` (rendered as the label, link-colored). */
internal fun inlineMarkdown(text: String): androidx.compose.ui.text.AnnotatedString {
    val builder = androidx.compose.ui.text.AnnotatedString.Builder()
    var i = 0
    while (i < text.length) {
        when {
            text.startsWith("**", i) -> {
                val end = text.indexOf("**", i + 2)
                if (end > i + 2) {
                    builder.pushStyle(
                        androidx.compose.ui.text.SpanStyle(fontWeight = androidx.compose.ui.text.font.FontWeight.Bold),
                    )
                    builder.append(text.substring(i + 2, end))
                    builder.pop()
                    i = end + 2
                } else {
                    builder.append(text[i]); i += 1
                }
            }
            text.startsWith("`", i) -> {
                val end = text.indexOf('`', i + 1)
                if (end > i + 1) {
                    builder.pushStyle(
                        androidx.compose.ui.text.SpanStyle(
                            fontFamily = androidx.compose.ui.text.font.FontFamily.Monospace,
                        ),
                    )
                    builder.append(text.substring(i + 1, end))
                    builder.pop()
                    i = end + 1
                } else {
                    builder.append(text[i]); i += 1
                }
            }
            (text.startsWith("*", i) && !text.startsWith("**", i)) || text.startsWith("_", i) -> {
                val marker = text[i]
                val end = text.indexOf(marker, i + 1)
                if (end > i + 1) {
                    builder.pushStyle(
                        androidx.compose.ui.text.SpanStyle(fontStyle = androidx.compose.ui.text.font.FontStyle.Italic),
                    )
                    builder.append(text.substring(i + 1, end))
                    builder.pop()
                    i = end + 1
                } else {
                    builder.append(text[i]); i += 1
                }
            }
            text.startsWith("[", i) -> {
                val labelEnd = text.indexOf(']', i + 1)
                val hasUrl = labelEnd > 0 && labelEnd + 1 < text.length && text[labelEnd + 1] == '('
                val urlEnd = if (hasUrl) text.indexOf(')', labelEnd + 2) else -1
                if (hasUrl && urlEnd > 0) {
                    builder.pushStyle(
                        androidx.compose.ui.text.SpanStyle(
                            color = androidx.compose.ui.graphics.Color(0xFF2A6BBF),
                            textDecoration = androidx.compose.ui.text.style.TextDecoration.Underline,
                        ),
                    )
                    builder.append(text.substring(i + 1, labelEnd))
                    builder.pop()
                    i = urlEnd + 1
                } else {
                    builder.append(text[i]); i += 1
                }
            }
            else -> {
                builder.append(text[i]); i += 1
            }
        }
    }
    return builder.toAnnotatedString()
}
