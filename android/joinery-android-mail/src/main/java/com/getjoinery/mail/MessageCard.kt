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
import android.webkit.WebResourceResponse
import com.getjoinery.android.JsonValue
import com.getjoinery.mail.fortress.FortressOpener
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
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
    opener: FortressOpener? = null,
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
                DangerBanner(message)
                if (message.fortressNote != null) {
                    Text(
                        message.fortressNote,
                        style = MaterialTheme.typography.bodyLarge,
                        fontStyle = androidx.compose.ui.text.font.FontStyle.Italic,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                        modifier = Modifier.testTag("mail_message_fortress_note"),
                    )
                } else if (message.bodyHtml.isNotEmpty()) {
                    MailHtmlBody(message.bodyHtml, endToEnd = message.sealed != null)
                } else {
                    SelectionContainer {
                        Text(message.bodyPlain, style = MaterialTheme.typography.bodyLarge,
                            modifier = Modifier.testTag("mail_message_body_plain"))
                    }
                }
                if (message.attachments.isNotEmpty()) {
                    AttachmentChips(message.attachments, opener)
                }
            }
        }
        HorizontalDivider(color = MaterialTheme.colorScheme.outlineVariant)
    }
}

private fun previewLine(message: MailMessage): String {
    message.fortressNote?.let { return it }
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
private fun MailHtmlBody(html: String, endToEnd: Boolean) {
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
                // B3: the body view never carries the bridged web session. An
                // end-to-end body loads nothing remote at all (its inline images
                // are already data: URLs, opened on this phone); a Standard
                // body's remote images are fetched below with no cookies.
                android.webkit.CookieManager.getInstance().setAcceptThirdPartyCookies(this, false)
                settings.blockNetworkLoads = endToEnd
                settings.cacheMode = android.webkit.WebSettings.LOAD_NO_CACHE
                isVerticalScrollBarEnabled = false
                isHorizontalScrollBarEnabled = false
                setBackgroundColor(android.graphics.Color.TRANSPARENT)
                webViewClient = object : WebViewClient() {
                    override fun shouldInterceptRequest(
                        view: WebView,
                        request: WebResourceRequest,
                    ): WebResourceResponse? {
                        val scheme = request.url.scheme?.lowercase() ?: return null
                        if (scheme != "http" && scheme != "https") return null
                        // No plain-http loads (a web page's mixed-content rule),
                        // and nothing remote at all in an end-to-end body.
                        if (endToEnd || scheme == "http" || request.method != "GET") return blockedResponse()
                        return cookieLessFetch(request.url.toString())
                    }

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

private fun blockedResponse() = WebResourceResponse("text/plain", "utf-8", 403, "Blocked", emptyMap(), java.io.ByteArrayInputStream(ByteArray(0)))

/** A remote resource of a mail body, fetched with no cookies and no
 *  credentials of any kind: the WebView's cookie jar (which holds the bridged
 *  web session) never sees the request (B3). */
private fun cookieLessFetch(url: String): WebResourceResponse {
    return try {
        val connection = URL(url).openConnection() as HttpURLConnection
        connection.instanceFollowRedirects = true
        connection.connectTimeout = 15_000
        connection.readTimeout = 20_000
        connection.setRequestProperty("Cookie", "")
        val code = connection.responseCode
        if (code !in 200..299) return blockedResponse()
        val type = (connection.contentType ?: "application/octet-stream").substringBefore(';').trim()
        val bytes = connection.inputStream.use { it.readBytes() }
        connection.disconnect()
        WebResourceResponse(type, null, java.io.ByteArrayInputStream(bytes))
    } catch (e: Exception) {
        blockedResponse()
    }
}

/** The AI security scan's banner: the score decides the tier; the scan (the
 *  owner's own model's, on a Fortress message) says what it found. */
@Composable
private fun DangerBanner(message: MailMessage) {
    val score = message.aiDangerScore ?: return
    if (message.aiScan.isEmpty() || message.fortressNote != null) return
    val scan = try { JsonValue.parse(message.aiScan) } catch (e: Exception) { return }
    val tier = if (score >= 7) 2 else if (score >= 5) 1 else 0
    val head = when (tier) { 2 -> "Danger"; 1 -> "Caution"; else -> "Security scan" }
    val color = when (tier) {
        2 -> MaterialTheme.colorScheme.errorContainer
        1 -> androidx.compose.ui.graphics.Color(0xFFFFF3CD)
        else -> MaterialTheme.colorScheme.surfaceVariant
    }
    Surface(color = color, shape = MaterialTheme.shapes.small, modifier = Modifier.fillMaxWidth().padding(bottom = 8.dp).testTag("mail_danger_banner")) {
        Column(Modifier.padding(10.dp), verticalArrangement = Arrangement.spacedBy(2.dp)) {
            Text("$head: $score/10", style = MaterialTheme.typography.labelLarge, fontWeight = FontWeight.SemiBold)
            scan["summary"]?.stringValue?.takeIf { it.isNotEmpty() }?.let { Text(it, style = MaterialTheme.typography.bodySmall) }
            scan["model"]?.stringValue?.takeIf { it.isNotEmpty() }?.let {
                Text("Judged by $it", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
            }
            if (tier > 0) {
                scan["red_flags"]?.arrayValue?.forEach { flag ->
                    flag["finding"]?.stringValue?.takeIf { it.isNotEmpty() }?.let { Text("• $it", style = MaterialTheme.typography.bodySmall) }
                }
            }
        }
    }
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
private fun AttachmentChips(attachments: List<MailAttachment>, opener: FortressOpener?) {
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
                                openAttachment(context, attachment, opener)
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

/** Fetch the part — for an end-to-end message, its ciphertext opened on this
 *  phone — stage it in the private cache (deleted when the person comes back,
 *  B4) and hand it to the system viewer. */
private suspend fun openAttachment(context: Context, attachment: MailAttachment, opener: FortressOpener?) {
    val bytes = if (attachment.fortress) {
        opener?.partBytes(attachment) ?: return
    } else {
        val urlString = attachment.url ?: return
        // Mail bytes travel over https only (the app allows cleartext for the
        // AI endpoint alone).
        val secure = com.getjoinery.android.ApiClient.requireHttps(urlString).toString()
        withContext(Dispatchers.IO) {
            val connection = URL(secure).openConnection() as HttpURLConnection
            try {
                if (connection.responseCode != 200) {
                    throw IllegalStateException("download failed: ${connection.responseCode}")
                }
                connection.inputStream.use { it.readBytes() }
            } finally {
                connection.disconnect()
            }
        }
    }
    val file = try {
        MailFiles.stage(context, attachment.filename, bytes)
    } finally {
        if (attachment.fortress) bytes.fill(0)
    }
    MailFiles.open(context, file, attachment.filename, attachment.contentType)
}
