package com.getjoinery.mail

import android.content.ActivityNotFoundException
import android.content.Context
import android.content.Intent
import android.net.Uri
import android.webkit.WebResourceRequest
import android.webkit.WebView
import android.webkit.WebViewClient
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.selection.SelectionContainer
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.AttachFile
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.viewinterop.AndroidView
import androidx.core.content.FileProvider
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.io.File
import java.net.HttpURLConnection
import java.net.URL

/**
 * One message inside a thread. Collapsed: header + one-line preview.
 * Expanded: full body — HTML in a sandboxed embedded WebView (JavaScript off,
 * link taps open externally), plain text natively — plus attachment chips
 * that download to cache and open into the system viewer/share sheet.
 */
@Composable
internal fun MessageCard(
    message: MailMessage,
    isExpanded: Boolean,
    onToggle: () -> Unit,
) {
    Column(Modifier.fillMaxWidth().testTag("mail_message_${message.id}")) {
        Row(
            Modifier
                .fillMaxWidth()
                .clickable(onClick = onToggle)
                .padding(horizontal = 16.dp, vertical = 10.dp),
            horizontalArrangement = Arrangement.spacedBy(12.dp),
            verticalAlignment = Alignment.Top,
        ) {
            SenderAvatar(
                seed = if (message.isOutbound) message.recipient else message.sender,
                size = 36.dp,
                initialOverride = if (message.isOutbound) "M" else null,
            )
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(
                        if (message.isOutbound) "Me" else MailDisplay.senderName(message.sender),
                        style = MaterialTheme.typography.bodyMedium,
                        fontWeight = if (message.isRead) FontWeight.Normal else FontWeight.SemiBold,
                        maxLines = 1,
                        overflow = TextOverflow.Ellipsis,
                        modifier = Modifier.weight(1f),
                    )
                    Spacer(Modifier.size(8.dp))
                    Text(
                        MailDisplay.messageStamp(message.receivedTime),
                        style = MaterialTheme.typography.labelSmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }
                if (isExpanded) {
                    Text(
                        "to ${MailDisplay.address(message.recipient)}",
                        style = MaterialTheme.typography.labelSmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                        maxLines = 1,
                        overflow = TextOverflow.Ellipsis,
                    )
                } else {
                    Text(
                        previewLine(message),
                        style = MaterialTheme.typography.bodyMedium,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                        maxLines = 1,
                        overflow = TextOverflow.Ellipsis,
                    )
                }
            }
        }
        if (isExpanded) {
            Column(Modifier.padding(start = 16.dp, end = 16.dp, bottom = 12.dp)) {
                if (message.bodyHtml.isNotEmpty()) {
                    MailHtmlBody(message.bodyHtml)
                } else {
                    SelectionContainer {
                        Text(message.bodyPlain, style = MaterialTheme.typography.bodyLarge)
                    }
                }
                if (message.attachments.isNotEmpty()) {
                    AttachmentChips(message.attachments)
                }
            }
        }
        HorizontalDivider(color = MaterialTheme.colorScheme.outlineVariant)
    }
}

private fun previewLine(message: MailMessage): String {
    val plain = message.bodyPlain.trim()
    if (plain.isNotEmpty()) return plain.replace("\n", " ")
    return if (message.attachments.isEmpty()) "" else "📎 ${message.attachments.size} attachment(s)"
}

// MARK: - Sandboxed HTML body

/**
 * Standard native-mail HTML rendering: JavaScript off, every link tap opens
 * externally, content capped to the device width. Inline images arrive as
 * short-lived signed URLs already rewritten server-side, so no session of
 * any kind exists inside this WebView. Height tracks the content (re-measured
 * once after remote images have had a moment to lay out).
 */
@Composable
private fun MailHtmlBody(html: String) {
    var heightDp by remember(html) { mutableStateOf(60) }
    val dark = isSystemInDarkTheme()

    AndroidView(
        modifier = Modifier.fillMaxWidth().height(heightDp.dp),
        factory = { ctx ->
            WebView(ctx).apply {
                // Content JavaScript stays off; the mail body is untrusted.
                settings.javaScriptEnabled = false
                settings.setSupportZoom(false)
                settings.allowFileAccess = false
                settings.allowContentAccess = false
                isVerticalScrollBarEnabled = false
                isHorizontalScrollBarEnabled = false
                setBackgroundColor(android.graphics.Color.TRANSPARENT)
                webViewClient = object : WebViewClient() {
                    override fun shouldOverrideUrlLoading(
                        view: WebView,
                        request: WebResourceRequest,
                    ): Boolean {
                        // The only allowed load is the HTML string itself; any
                        // link tap (http, mailto, …) leaves the app.
                        openExternally(view.context, request.url)
                        return true
                    }

                    override fun onPageFinished(view: WebView, url: String?) {
                        fun measure() {
                            val content = view.contentHeight
                            if (content > 0) heightDp = maxOf(24, content)
                        }
                        measure()
                        // Remote inline images can land after onPageFinished;
                        // re-measure once they have had a moment to lay out.
                        view.postDelayed({ measure() }, 800)
                    }
                }
                loadDataWithBaseURL(null, wrapMailHtml(html, dark), "text/html", "utf-8", null)
            }
        },
    )
}

private fun openExternally(context: Context, url: Uri) {
    try {
        context.startActivity(Intent(Intent.ACTION_VIEW, url).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
    } catch (e: ActivityNotFoundException) {
        // No handler for the scheme — drop the tap.
    }
}

/** Viewport + typography wrapper so arbitrary mail HTML reads well on a
 *  phone: system font, images capped to the width, no sideways scrolling. */
internal fun wrapMailHtml(body: String, dark: Boolean): String {
    val darkCss = if (dark) "body { color: #eee; } a { color: #7ab8ff; }" else ""
    return """
        <!doctype html><html><head>
        <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=2">
        <style>
        body { font-family: sans-serif; font-size: 16px;
               margin: 0; padding: 0; word-wrap: break-word; overflow-wrap: break-word; }
        img { max-width: 100% !important; height: auto !important; }
        table { max-width: 100% !important; }
        pre, blockquote { white-space: pre-wrap; overflow-x: hidden; }
        $darkCss
        </style></head><body>$body</body></html>
    """.trimIndent()
}

// MARK: - Attachments

@Composable
private fun AttachmentChips(attachments: List<MailAttachment>) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var downloadingId by remember { mutableStateOf<Int?>(null) }

    Row(
        Modifier
            .fillMaxWidth()
            .horizontalScroll(rememberScrollState())
            .padding(top = 12.dp),
        horizontalArrangement = Arrangement.spacedBy(8.dp),
    ) {
        attachments.forEach { attachment ->
            Surface(
                shape = MaterialTheme.shapes.large,
                color = MaterialTheme.colorScheme.surfaceVariant,
                modifier = Modifier
                    .testTag("mail_attachment_${attachment.id}")
                    .clickable(enabled = attachment.url != null && downloadingId == null) {
                        downloadingId = attachment.id
                        scope.launch {
                            try {
                                openAttachment(context, attachment)
                            } catch (e: Exception) {
                                // Transient failure — the chip stays tappable to retry.
                            } finally {
                                downloadingId = null
                            }
                        }
                    },
            ) {
                Row(
                    Modifier.padding(horizontal = 12.dp, vertical = 8.dp),
                    horizontalArrangement = Arrangement.spacedBy(6.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    if (downloadingId == attachment.id) {
                        CircularProgressIndicator(Modifier.size(16.dp), strokeWidth = 2.dp)
                    } else {
                        Icon(
                            Icons.Outlined.AttachFile,
                            contentDescription = null,
                            modifier = Modifier.size(16.dp),
                            tint = MaterialTheme.colorScheme.onSurfaceVariant,
                        )
                    }
                    Column {
                        Text(
                            attachment.filename,
                            style = MaterialTheme.typography.labelMedium,
                            maxLines = 1,
                            overflow = TextOverflow.Ellipsis,
                        )
                        Text(
                            attachment.sizeLabel,
                            style = MaterialTheme.typography.labelSmall,
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                        )
                    }
                }
            }
        }
    }
}

/** Fetch the signed URL to a cache file and hand it to the system viewer via
 *  a chooser (the same hand-off the webview downloads use). */
private suspend fun openAttachment(context: Context, attachment: MailAttachment) {
    val urlString = attachment.url ?: return
    val file = withContext(Dispatchers.IO) {
        val dir = File(context.cacheDir, "mail_attachments")
        dir.mkdirs()
        // Path-safe name; the display name is preserved for the receiving app.
        val safe = attachment.filename.replace(Regex("[/\\\\ ]"), "_")
        val target = File(dir, safe)
        val connection = URL(urlString).openConnection() as HttpURLConnection
        try {
            if (connection.responseCode != 200) {
                throw IllegalStateException("download failed: ${connection.responseCode}")
            }
            connection.inputStream.use { input ->
                target.outputStream().use { output -> input.copyTo(output) }
            }
        } finally {
            connection.disconnect()
        }
        target
    }
    val uri = FileProvider.getUriForFile(
        context, "${context.packageName}.joinerymail.files", file,
    )
    val view = Intent(Intent.ACTION_VIEW)
        .setDataAndType(uri, attachment.contentType.ifEmpty { "application/octet-stream" })
        .addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
    try {
        context.startActivity(Intent.createChooser(view, attachment.filename))
    } catch (e: ActivityNotFoundException) {
        // Nothing can open it — fall back to a share intent.
        val send = Intent(Intent.ACTION_SEND)
            .setType(attachment.contentType.ifEmpty { "application/octet-stream" })
            .putExtra(Intent.EXTRA_STREAM, uri)
            .addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
        context.startActivity(Intent.createChooser(send, attachment.filename))
    }
}
