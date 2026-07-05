package com.getjoinery.mail

import android.content.Context
import android.net.Uri
import android.provider.OpenableColumns
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.Send
import androidx.compose.material.icons.outlined.AttachFile
import androidx.compose.material.icons.outlined.Close
import androidx.compose.material.icons.outlined.Description
import androidx.compose.material.icons.outlined.Image
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.ExposedDropdownMenuBox
import androidx.compose.material3.ExposedDropdownMenuDefaults
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.text.input.KeyboardCapitalization
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.window.Dialog
import androidx.compose.ui.window.DialogProperties
import com.getjoinery.android.JoineryApiError
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

/**
 * Reply / reply-all / forward / new-message compose. Deliberately lean: the
 * server is the authority on quoting, subject normalization (Re:/Fwd:),
 * threading headers, and the sending identity (for a new message, the picked
 * mailbox) — this sheet collects recipients and the new text. A forward
 * re-attaches the original server-side; new uploads (any mode) attach here
 * via the system picker, mirroring ComposeSheet.swift.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun ComposeSheet(
    api: MailApi,
    request: ComposeRequest,
    mailboxes: List<Mailbox>,
    preselectedAlias: Int?,
    onDismiss: () -> Unit,
    onSent: () -> Unit,
) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val source = request.source

    var to by remember {
        mutableStateOf(
            when (request.mode) {
                ComposeMode.REPLY, ComposeMode.REPLY_ALL ->
                    // Replying to your own outbound message goes back to its
                    // recipient; otherwise to the sender.
                    source?.let { MailDisplay.address(if (it.isOutbound) it.recipient else it.sender) } ?: ""
                else -> ""
            },
        )
    }
    var cc by remember { mutableStateOf("") }
    var subject by remember {
        mutableStateOf(
            when (request.mode) {
                ComposeMode.REPLY, ComposeMode.REPLY_ALL -> prefixed(source?.subject ?: "", "Re:")
                ComposeMode.FORWARD -> prefixed(source?.subject ?: "", "Fwd:")
                ComposeMode.NEW -> ""
            },
        )
    }
    var fromAlias by remember {
        mutableStateOf(
            if (request.mode == ComposeMode.NEW) {
                preselectedAlias ?: mailboxes.firstOrNull()?.aliasId
            } else null,
        )
    }
    var bodyText by remember { mutableStateOf("") }
    val attachments = remember { mutableStateListOf<MailOutgoingAttachment>() }
    var isSending by remember { mutableStateOf(false) }
    var failure by remember { mutableStateOf<String?>(null) }

    fun addPicked(uris: List<Uri>) {
        scope.launch {
            for (uri in uris) {
                val picked = withContext(Dispatchers.IO) { readAttachment(context, uri) } ?: continue
                failure = preflight(attachments, picked)
                if (failure == null) attachments.add(picked)
            }
        }
    }

    val filePicker = rememberLauncherForActivityResult(
        ActivityResultContracts.GetMultipleContents(),
    ) { uris -> addPicked(uris) }

    val title = when (request.mode) {
        ComposeMode.REPLY -> "Reply"
        ComposeMode.REPLY_ALL -> "Reply all"
        ComposeMode.FORWARD -> "Forward"
        ComposeMode.NEW -> "New message"
    }
    val footerText = when (request.mode) {
        ComposeMode.FORWARD -> "The forwarded message and its attachments are included below your text."
        ComposeMode.NEW -> null
        else -> "The original message is quoted below your text."
    }
    val canSend = !isSending && to.trim().isNotEmpty() &&
        !(request.mode == ComposeMode.NEW && fromAlias == null)

    fun send() {
        isSending = true
        failure = null
        scope.launch {
            try {
                api.send(
                    mode = request.mode,
                    sourceId = source?.id,
                    aliasId = if (request.mode == ComposeMode.NEW) fromAlias else null,
                    to = to,
                    cc = cc,
                    subject = subject,
                    body = bodyText,
                    attachments = attachments.toList(),
                )
                isSending = false
                onSent()
            } catch (e: Exception) {
                isSending = false
                failure = (e as? JoineryApiError)?.displayMessage ?: (e.message ?: "Send failed.")
            }
        }
    }

    Dialog(
        onDismissRequest = { if (!isSending) onDismiss() },
        properties = DialogProperties(usePlatformDefaultWidth = false, decorFitsSystemWindows = true),
    ) {
        Surface(Modifier.fillMaxSize()) {
            Scaffold(topBar = {
                TopAppBar(
                    title = { Text(title, maxLines = 1) },
                    navigationIcon = {
                        IconButton(
                            onClick = onDismiss,
                            enabled = !isSending,
                            modifier = Modifier.testTag("mail_compose_cancel"),
                        ) {
                            Icon(Icons.Outlined.Close, contentDescription = "Cancel")
                        }
                    },
                    actions = {
                        AttachMenuButton(
                            enabled = !isSending && attachments.size < MAX_ATTACHMENTS,
                            onPickPhotos = { filePicker.launch("image/*") },
                            onPickFiles = { filePicker.launch("*/*") },
                        )
                        IconButton(
                            onClick = { send() },
                            enabled = canSend,
                            modifier = Modifier.testTag("mail_compose_send"),
                        ) {
                            if (isSending) {
                                CircularProgressIndicator(Modifier.size(20.dp), strokeWidth = 2.dp)
                            } else {
                                Icon(Icons.AutoMirrored.Filled.Send, contentDescription = "Send")
                            }
                        }
                    },
                )
            }) { padding ->
                Column(
                    Modifier
                        .fillMaxSize()
                        .padding(padding)
                        .verticalScroll(rememberScrollState())
                        .padding(horizontal = 16.dp, vertical = 8.dp),
                    verticalArrangement = Arrangement.spacedBy(8.dp),
                ) {
                    if (request.mode == ComposeMode.NEW) {
                        FromPicker(mailboxes, fromAlias) { fromAlias = it }
                    }
                    OutlinedTextField(
                        value = to,
                        onValueChange = { to = it },
                        label = { Text("To") },
                        singleLine = true,
                        keyboardOptions = KeyboardOptions(
                            keyboardType = KeyboardType.Email,
                            capitalization = KeyboardCapitalization.None,
                            autoCorrect = false,
                        ),
                        modifier = Modifier.fillMaxWidth().testTag("mail_compose_to"),
                    )
                    if (request.mode != ComposeMode.FORWARD) {
                        OutlinedTextField(
                            value = cc,
                            onValueChange = { cc = it },
                            label = { Text("Cc") },
                            singleLine = true,
                            keyboardOptions = KeyboardOptions(
                                keyboardType = KeyboardType.Email,
                                capitalization = KeyboardCapitalization.None,
                                autoCorrect = false,
                            ),
                            modifier = Modifier.fillMaxWidth().testTag("mail_compose_cc"),
                        )
                    }
                    OutlinedTextField(
                        value = subject,
                        onValueChange = { subject = it },
                        label = { Text("Subject") },
                        singleLine = true,
                        modifier = Modifier.fillMaxWidth().testTag("mail_compose_subject"),
                    )
                    OutlinedTextField(
                        value = bodyText,
                        onValueChange = { bodyText = it },
                        label = { Text("Message") },
                        modifier = Modifier
                            .fillMaxWidth()
                            .heightIn(min = 180.dp)
                            .testTag("mail_compose_body"),
                    )
                    footerText?.let {
                        Text(
                            it,
                            style = MaterialTheme.typography.labelMedium,
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                        )
                    }
                    attachments.forEach { att ->
                        Row(
                            Modifier.fillMaxWidth(),
                            horizontalArrangement = Arrangement.spacedBy(8.dp),
                            verticalAlignment = Alignment.CenterVertically,
                        ) {
                            Icon(
                                if (att.mimeType.startsWith("image/")) Icons.Outlined.Image
                                else Icons.Outlined.Description,
                                contentDescription = null,
                                tint = MaterialTheme.colorScheme.onSurfaceVariant,
                            )
                            Text(
                                att.filename,
                                maxLines = 1,
                                overflow = TextOverflow.Ellipsis,
                                modifier = Modifier.weight(1f),
                            )
                            IconButton(
                                onClick = { attachments.removeAll { it.id == att.id } },
                                modifier = Modifier.testTag("mail_compose_attachment_remove"),
                            ) {
                                Icon(Icons.Outlined.Close, contentDescription = "Remove attachment")
                            }
                        }
                    }
                    failure?.let {
                        Text(
                            it,
                            color = MaterialTheme.colorScheme.error,
                            modifier = Modifier.testTag("mail_compose_error"),
                        )
                    }
                }
            }
        }
    }
}

@Composable
private fun AttachMenuButton(
    enabled: Boolean,
    onPickPhotos: () -> Unit,
    onPickFiles: () -> Unit,
) {
    var open by remember { mutableStateOf(false) }
    IconButton(
        onClick = { open = true },
        enabled = enabled,
        modifier = Modifier.testTag("mail_compose_attach"),
    ) {
        Icon(Icons.Outlined.AttachFile, contentDescription = "Attach")
    }
    DropdownMenu(expanded = open, onDismissRequest = { open = false }) {
        DropdownMenuItem(
            text = { Text("Photos") },
            leadingIcon = { Icon(Icons.Outlined.Image, contentDescription = null) },
            onClick = { open = false; onPickPhotos() },
        )
        DropdownMenuItem(
            text = { Text("Files") },
            leadingIcon = { Icon(Icons.Outlined.Description, contentDescription = null) },
            onClick = { open = false; onPickFiles() },
        )
    }
}

/** The sending identity for a new message — a picker over the granted
 *  mailboxes (reply/forward keep their implicit source-derived identity). */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun FromPicker(
    mailboxes: List<Mailbox>,
    selected: Int?,
    onSelect: (Int) -> Unit,
) {
    var open by remember { mutableStateOf(false) }
    val current = mailboxes.firstOrNull { it.aliasId == selected }

    ExposedDropdownMenuBox(expanded = open, onExpandedChange = { open = it }) {
        OutlinedTextField(
            value = current?.address ?: "",
            onValueChange = {},
            readOnly = true,
            label = { Text("From") },
            trailingIcon = { ExposedDropdownMenuDefaults.TrailingIcon(expanded = open) },
            modifier = Modifier.fillMaxWidth().menuAnchor().testTag("mail_compose_from"),
        )
        ExposedDropdownMenu(expanded = open, onDismissRequest = { open = false }) {
            mailboxes.forEach { box ->
                DropdownMenuItem(
                    text = { Text(box.address) },
                    onClick = {
                        open = false
                        onSelect(box.aliasId)
                    },
                )
            }
        }
    }
}

// MARK: - Picking

/** Preflight only — mirrors the server's real caps
 *  (`MailboxSender::MAX_UPLOAD_FILES/MAX_UPLOAD_BYTES/MAX_TOTAL_BYTES`); the
 *  server remains the authority and re-validates every file and the total. */
private const val MAX_ATTACHMENTS = 10
private const val MAX_ATTACHMENT_BYTES = 10_485_760
private const val MAX_TOTAL_BYTES = 26_214_400

private fun preflight(
    attachments: List<MailOutgoingAttachment>,
    candidate: MailOutgoingAttachment,
): String? {
    if (attachments.size >= MAX_ATTACHMENTS) {
        return "Up to $MAX_ATTACHMENTS attachments per message."
    }
    if (candidate.data.size > MAX_ATTACHMENT_BYTES) {
        return "\"${candidate.filename}\" is larger than the per-file limit."
    }
    val total = attachments.sumOf { it.data.size } + candidate.data.size
    if (total > MAX_TOTAL_BYTES) {
        return "The attachments exceed the total size limit."
    }
    return null
}

/** Resolve a picked content Uri to bytes + display name + type. The server
 *  re-detects the type from bytes; email carries arbitrary file types, so
 *  nothing is transcoded or filtered here. */
private fun readAttachment(context: Context, uri: Uri): MailOutgoingAttachment? {
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
        MailOutgoingAttachment(filename = name, mimeType = mime, data = data)
    } catch (e: Exception) {
        null
    }
}

private fun prefixed(subject: String, prefix: String): String {
    val trimmed = subject.trim()
    if (trimmed.lowercase().startsWith(prefix.lowercase())) return trimmed
    return "$prefix $trimmed"
}
