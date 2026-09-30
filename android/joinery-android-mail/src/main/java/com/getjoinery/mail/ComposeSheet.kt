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
import androidx.compose.material.icons.outlined.Delete
import androidx.compose.material.icons.outlined.Lock
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.ui.platform.LocalLifecycleOwner
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleEventObserver
import com.getjoinery.android.JsonValue
import com.getjoinery.android.vault.BiometricGate
import com.getjoinery.mail.drafts.ComposeContent
import com.getjoinery.mail.drafts.MailDrafts
import com.getjoinery.mail.drafts.SavedPart
import com.getjoinery.mail.fortress.FortressMail
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

/**
 * Reply / reply-all / forward / new-message compose, and a draft reopened
 * from Drafts. The server is the authority on quoting, subject normalization
 * (Re:/Fwd:), threading headers and the sending identity — except for
 * end-to-end mail, which it cannot read: a reply or forward of an opened
 * Fortress message carries the quote this phone opened (`source_open`) and a
 * forward its parts, opened here (specs/fortress_mobile_apps.md § R6).
 *
 * Drafts (§ R12): the sheet autosaves three seconds after the last edit and
 * when the app leaves the foreground or the sheet closes; a send turns the
 * draft into the Sent row; Discard deletes it. A Fortress draft is sealed on
 * the phone under its own key; when the mail key locks, the sheet (already
 * saved) closes and drops that key.
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
    fortress: FortressMail? = null,
) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val source = request.source
    val drafts = remember { MailDrafts(api.client, fortress?.opener) }

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
    var mode by remember { mutableStateOf(request.mode) }
    var sourceId by remember { mutableStateOf(source?.id) }
    var fromAlias by remember {
        mutableStateOf(
            if (request.mode == ComposeMode.NEW) {
                preselectedAlias ?: mailboxes.firstOrNull()?.aliasId
            } else source?.aliasId,
        )
    }
    var bodyText by remember { mutableStateOf("") }
    val attachments = remember { mutableStateListOf<MailOutgoingAttachment>() }
    val savedParts = remember { mutableStateListOf<SavedPart>() }
    var isSending by remember { mutableStateOf(false) }
    var failure by remember { mutableStateOf<String?>(null) }
    var notice by remember { mutableStateOf<String?>(null) }
    var loadingDraft by remember { mutableStateOf(request.draftId != null) }
    var dirty by remember { mutableStateOf(false) }
    var saving by remember { mutableStateOf<Job?>(null) }
    var autosaveJob by remember { mutableStateOf<Job?>(null) }

    val composeMailbox = mailboxes.firstOrNull { it.aliasId == fromAlias }
    val isFortress = composeMailbox?.isFortress == true
    val session = remember(isFortress) { MailDrafts.Session(fortress = isFortress) }

    fun content() = ComposeContent(
        aliasId = fromAlias, mode = mode, sourceId = sourceId,
        sender = composeMailbox?.address ?: "", to = to, cc = cc, subject = subject, body = bodyText,
    )

    /** Save now (one save at a time); returns when it settled. */
    suspend fun saveNow() {
        saving?.join()
        if (!dirty || !(content().hasContent || attachments.isNotEmpty())) return
        if (session.fortress && fortress?.isOpen != true && session.dek == null) return
        val job = scope.launch {
            try {
                val pending = attachments.toList()
                val persisted = drafts.save(session, content(), pending, savedParts.map { it.mimePart }.toSet())
                attachments.removeAll { a -> persisted.any { it.id == a.id } }
                savedParts.clear()
                savedParts.addAll(session.parts.filter { !it.inline || session.fortress })
                dirty = false
                notice = "Draft saved"
            } catch (e: Exception) {
                failure = "This draft could not be saved: " +
                    ((e as? JoineryApiError)?.displayMessage ?: (e.message ?: "unknown error"))
            }
        }
        saving = job
        job.join()
        saving = null
    }

    fun markDirty() {
        dirty = true
        notice = null
        autosaveJob?.cancel()
        autosaveJob = scope.launch {
            delay(3_000)
            saveNow()
        }
    }

    fun closeSavingFirst() {
        autosaveJob?.cancel()
        scope.launch {
            saveNow()
            session.wipe()
            onDismiss()
        }
    }

    // A draft reopened from Drafts.
    LaunchedEffect(request.draftId) {
        val id = request.draftId ?: return@LaunchedEffect
        try {
            // A Fortress draft opens with the mail key: ask for it first.
            if (fortress != null && fortress.holdsKey && !fortress.isOpen) {
                BiometricGate.activity(context)?.let { fortress.unlock(it) }
            }
            val (opened, s) = drafts.open(id)
            session.draftId = s.draftId
            session.adPrefix = s.adPrefix
            session.dek = s.dek
            session.parts.clear(); session.parts.addAll(s.parts)
            val c = opened.content
            fromAlias = c.aliasId ?: fromAlias
            mode = c.mode
            sourceId = c.sourceId
            to = c.to; cc = c.cc; subject = c.subject; bodyText = c.body
            savedParts.clear(); savedParts.addAll(opened.parts)
            loadingDraft = false
        } catch (e: Exception) {
            loadingDraft = false
            failure = (e as? JoineryApiError)?.displayMessage ?: (e.message ?: "This draft could not be opened.")
        }
    }

    // Leaving the app saves the compose; a lock of the mail key closes a
    // Fortress compose (already saved) and drops its key (§ R12 Lock).
    val lifecycleOwner = LocalLifecycleOwner.current
    DisposableEffect(lifecycleOwner, isFortress) {
        val observer = LifecycleEventObserver { _, event ->
            if (event == Lifecycle.Event.ON_STOP) {
                autosaveJob?.cancel()
                scope.launch { saveNow() }
            }
        }
        lifecycleOwner.lifecycle.addObserver(observer)
        val onLock: () -> Unit = {
            if (session.fortress) {
                scope.launch {
                    saving?.join()
                    session.wipe()
                    onDismiss()
                }
            }
        }
        fortress?.keys?.held?.onLock(onLock)
        onDispose {
            lifecycleOwner.lifecycle.removeObserver(observer)
            fortress?.keys?.held?.removeOnLock(onLock)
        }
    }

    fun addPicked(uris: List<Uri>) {
        scope.launch {
            for (uri in uris) {
                val picked = withContext(Dispatchers.IO) { readAttachment(context, uri) } ?: continue
                failure = preflight(attachments, picked)
                if (failure == null) {
                    attachments.add(picked)
                    markDirty()
                }
            }
        }
    }

    val filePicker = rememberLauncherForActivityResult(
        ActivityResultContracts.GetMultipleContents(),
    ) { uris -> addPicked(uris) }

    val title = when {
        request.draftId != null -> "Draft"
        else -> when (mode) {
            ComposeMode.REPLY -> "Reply"
            ComposeMode.REPLY_ALL -> "Reply all"
            ComposeMode.FORWARD -> "Forward"
            ComposeMode.NEW -> "New message"
        }
    }
    val footerText = when (mode) {
        ComposeMode.FORWARD -> "The forwarded message and its attachments are included below your text."
        ComposeMode.NEW -> null
        else -> "The original message is quoted below your text."
    }
    val canSend = !isSending && !loadingDraft && to.trim().isNotEmpty() &&
        !(mode == ComposeMode.NEW && fromAlias == null)

    fun send() {
        isSending = true
        failure = null
        autosaveJob?.cancel()
        scope.launch {
            try {
                saving?.join()
                val outgoing = ArrayList<MailOutgoingAttachment>(attachments)
                val inline = LinkedHashMap<String, String>()
                var sourceOpen: JsonValue? = null
                // An end-to-end source: the quote and, on a forward, the parts
                // this phone opened — the server cannot read them.
                if (source != null && source.sealed != null && mode != ComposeMode.NEW) {
                    if (!source.isOpenedFortress) {
                        throw IllegalStateException("Unlock and open the message again, then send.")
                    }
                    sourceOpen = JsonValue.obj(
                        "sender" to JsonValue.Str(source.sender),
                        "subject" to JsonValue.Str(source.subject),
                        "recipient" to JsonValue.Str(source.recipient),
                        "body_html" to JsonValue.Str(source.bodyHtmlSource ?: source.bodyHtml),
                        "body_plain" to JsonValue.Str(source.bodyPlain),
                    )
                    if (mode == ComposeMode.FORWARD && fortress != null) {
                        for (a in source.attachments) {
                            if (!a.fortress) continue
                            outgoing.add(MailOutgoingAttachment(filename = a.filename, mimeType = a.contentType, data = fortress.opener.partBytes(a)))
                        }
                        source.inlineParts.forEachIndexed { j, p ->
                            val cid = p.contentId.trim('<', '>')
                            if (cid.isEmpty()) return@forEachIndexed
                            val name = "fwdinl$j-" + p.filename.ifEmpty { "image" }.replace(Regex("[^A-Za-z0-9._-]"), "_")
                            outgoing.add(MailOutgoingAttachment(filename = name, mimeType = p.contentType, data = fortress.opener.partBytes(p)))
                            inline[cid] = name
                        }
                    }
                }
                // A Fortress draft's saved parts: opened here and posted with the rest.
                if (session.fortress && session.draftId != null) {
                    outgoing.addAll(drafts.openedParts(session, savedParts.map { it.mimePart }.toSet()))
                }
                val result = api.send(
                    mode = mode,
                    sourceId = if (mode != ComposeMode.NEW) sourceId else null,
                    aliasId = if (mode == ComposeMode.NEW) fromAlias else null,
                    to = to,
                    cc = cc,
                    subject = subject,
                    body = bodyText,
                    attachments = outgoing,
                    sourceOpen = sourceOpen,
                    inlineManifest = inline,
                    draftId = session.draftId,
                )
                isSending = false
                when (result) {
                    is MailApi.SendResult.Locked -> {
                        // B2: nothing was sent. Keep the sheet and the words.
                        dirty = true
                        saveNow()
                        failure = result.message + " The sending lock opens in a browser: send this from a computer." +
                            if (session.draftId != null) " Your draft is saved." else ""
                    }
                    is MailApi.SendResult.Sent -> {
                        session.wipe()
                        onSent()
                    }
                }
            } catch (e: Exception) {
                isSending = false
                failure = (e as? JoineryApiError)?.displayMessage ?: (e.message ?: "Send failed.")
            }
        }
    }

    fun discard() {
        autosaveJob?.cancel()
        scope.launch {
            saving?.join()
            session.draftId?.let { id -> try { drafts.delete(id) } catch (_: Exception) {} }
            session.wipe()
            onDismiss()
        }
    }

    Dialog(
        onDismissRequest = { if (!isSending) closeSavingFirst() },
        properties = DialogProperties(usePlatformDefaultWidth = false, decorFitsSystemWindows = true),
    ) {
        Surface(Modifier.fillMaxSize()) {
            Scaffold(topBar = {
                TopAppBar(
                    title = { Text(title, maxLines = 1) },
                    navigationIcon = {
                        IconButton(
                            onClick = { closeSavingFirst() },
                            enabled = !isSending,
                            modifier = Modifier.testTag("mail_compose_cancel"),
                        ) {
                            Icon(Icons.Outlined.Close, contentDescription = "Close and keep draft")
                        }
                    },
                    actions = {
                        IconButton(
                            onClick = { discard() },
                            enabled = !isSending,
                            modifier = Modifier.testTag("mail_compose_discard"),
                        ) {
                            Icon(Icons.Outlined.Delete, contentDescription = "Discard draft")
                        }
                        AttachMenuButton(
                            enabled = !isSending && attachments.size + savedParts.size < MAX_ATTACHMENTS,
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
                    if (loadingDraft) {
                        CircularProgressIndicator(Modifier.size(24.dp).testTag("mail_compose_loading"))
                    }
                    if (isFortress) {
                        Row(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalAlignment = Alignment.CenterVertically) {
                            Icon(Icons.Outlined.Lock, contentDescription = null, modifier = Modifier.size(16.dp),
                                tint = MaterialTheme.colorScheme.onSurfaceVariant)
                            Text(
                                "Drafts from this mailbox are sealed on this phone. The message itself goes to its recipients as ordinary email.",
                                style = MaterialTheme.typography.labelMedium,
                                color = MaterialTheme.colorScheme.onSurfaceVariant,
                            )
                        }
                    }
                    if (mode == ComposeMode.NEW) {
                        FromPicker(mailboxes, fromAlias) { fromAlias = it; markDirty() }
                    }
                    OutlinedTextField(
                        value = to,
                        onValueChange = { to = it; markDirty() },
                        label = { Text("To") },
                        singleLine = true,
                        keyboardOptions = KeyboardOptions(
                            keyboardType = KeyboardType.Email,
                            capitalization = KeyboardCapitalization.None,
                            autoCorrect = false,
                        ),
                        modifier = Modifier.fillMaxWidth().testTag("mail_compose_to"),
                    )
                    if (mode != ComposeMode.FORWARD) {
                        OutlinedTextField(
                            value = cc,
                            onValueChange = { cc = it; markDirty() },
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
                        onValueChange = { subject = it; markDirty() },
                        label = { Text("Subject") },
                        singleLine = true,
                        modifier = Modifier.fillMaxWidth().testTag("mail_compose_subject"),
                    )
                    OutlinedTextField(
                        value = bodyText,
                        onValueChange = { bodyText = it; markDirty() },
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
                    savedParts.forEach { part ->
                        AttachmentRow(part.filename, part.contentType, "mail_compose_saved_attachment") {
                            savedParts.removeAll { it.mimePart == part.mimePart && it.id == part.id }
                            val draftId = session.draftId
                            if (!session.fortress && draftId != null && part.id != null) {
                                scope.launch { try { drafts.deleteAttachment(draftId, part.id) } catch (_: Exception) {} }
                            }
                            markDirty()
                        }
                    }
                    attachments.forEach { att ->
                        AttachmentRow(att.filename, att.mimeType, "mail_compose_attachment") {
                            attachments.removeAll { it.id == att.id }
                            markDirty()
                        }
                    }
                    notice?.let {
                        Text(it, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant,
                            modifier = Modifier.testTag("mail_compose_notice"))
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
private fun AttachmentRow(filename: String, mimeType: String, tag: String, onRemove: () -> Unit) {
    Row(
        Modifier.fillMaxWidth().testTag(tag),
        horizontalArrangement = Arrangement.spacedBy(8.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(
            if (mimeType.startsWith("image/")) Icons.Outlined.Image else Icons.Outlined.Description,
            contentDescription = null,
            tint = MaterialTheme.colorScheme.onSurfaceVariant,
        )
        Text(filename, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f))
        IconButton(onClick = onRemove, modifier = Modifier.testTag("mail_compose_attachment_remove")) {
            Icon(Icons.Outlined.Close, contentDescription = "Remove attachment")
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
