package com.getjoinery.mail

import com.getjoinery.android.JsonValue
import java.text.DateFormat
import java.text.SimpleDateFormat
import java.util.Calendar
import java.util.Date
import java.util.Locale
import java.util.TimeZone
import java.util.UUID

/** One tracked folder/label on a mailbox — the unit the reader's Move/Labels
 *  control and folder-filtered views operate on. */
data class MailFolder(
    val id: Int,
    val name: String,
    val role: String,
) {
    companion object {
        fun from(json: JsonValue): MailFolder? {
            val id = json["id"]?.intValue ?: return null
            return MailFolder(
                id = id,
                name = json["name"]?.stringValue ?: "",
                role = json["role"]?.stringValue ?: "custom",
            )
        }
    }
}

/** One granted mailbox from `mailbox/mailboxes`. `foldersExclusive`
 *  drives whether the folder control is a single-pick "Move" (exclusive feed)
 *  or checkbox "Labels" (Gmail-style). */
data class Mailbox(
    val aliasId: Int,
    val address: String,
    val unread: Int,
    val total: Int,
    val folders: List<MailFolder>,
    val foldersExclusive: Boolean,
) {
    /** The local part — what the switcher shows when every grant shares a domain. */
    val localPart: String
        get() = address.substringBefore('@')

    companion object {
        fun from(json: JsonValue): Mailbox? {
            val aliasId = json["alias_id"]?.intValue ?: return null
            val address = json["address"]?.stringValue ?: return null
            if (address.isEmpty()) return null
            return Mailbox(
                aliasId = aliasId,
                address = address,
                unread = json["unread"]?.intValue ?: 0,
                total = json["total"]?.intValue ?: 0,
                folders = (json["folders"]?.arrayValue ?: emptyList())
                    .mapNotNull { MailFolder.from(it) },
                foldersExclusive = json["folders_exclusive"]?.boolValue ?: false,
            )
        }
    }
}

/** The `mailbox/mailboxes` payload. */
data class MailboxHome(
    val mailboxes: List<Mailbox>,
    val canCompose: Boolean,
) {
    companion object {
        fun from(data: JsonValue?): MailboxHome? {
            if (data == null) return null
            return MailboxHome(
                mailboxes = (data["mailboxes"]?.arrayValue ?: emptyList())
                    .mapNotNull { Mailbox.from(it) },
                canCompose = data["can_compose"]?.boolValue ?: false,
            )
        }
    }
}

/** One row of `mailbox/thread_list`. */
data class ThreadSummary(
    val threadKey: String,
    val subject: String,
    val sender: String,
    val snippet: String,
    val messageCount: Int,
    val unreadCount: Int,
    val isStarred: Boolean,
    val isArchived: Boolean,
    val latestTime: String,
) {
    val hasUnread: Boolean get() = unreadCount > 0

    companion object {
        fun from(json: JsonValue): ThreadSummary? {
            val key = json["thread_key"]?.stringValue ?: return null
            if (key.isEmpty()) return null
            return ThreadSummary(
                threadKey = key,
                subject = json["subject"]?.stringValue ?: "",
                sender = json["sender"]?.stringValue ?: "",
                snippet = json["snippet"]?.stringValue ?: "",
                messageCount = json["msg_count"]?.intValue ?: 1,
                unreadCount = json["unread_count"]?.intValue ?: 0,
                isStarred = json["any_starred"]?.boolValue ?: false,
                isArchived = json["any_archived"]?.boolValue ?: false,
                latestTime = json["latest_time"]?.stringValue ?: "",
            )
        }
    }
}

/** The `mailbox/thread_list` payload. */
data class ThreadPage(
    val threads: List<ThreadSummary>,
    val hasMore: Boolean,
    val page: Int,
) {
    companion object {
        fun from(data: JsonValue?): ThreadPage? {
            if (data == null) return null
            return ThreadPage(
                threads = (data["threads"]?.arrayValue ?: emptyList())
                    .mapNotNull { ThreadSummary.from(it) },
                hasMore = data["has_more"]?.boolValue ?: false,
                page = data["page"]?.intValue ?: 1,
            )
        }
    }
}

/** A non-inline attachment on a message. [url] is a short-lived signed
 *  download URL when the bytes are file-backed, null otherwise. */
data class MailAttachment(
    val id: Int,
    val filename: String,
    val contentType: String,
    val sizeBytes: Int,
    val url: String?,
) {
    val sizeLabel: String
        get() {
            val bytes = sizeBytes.toDouble()
            if (bytes >= 1_048_576) return String.format(Locale.US, "%.1f MB", bytes / 1_048_576)
            if (bytes >= 1024) return String.format(Locale.US, "%.0f KB", bytes / 1024)
            return "$sizeBytes B"
        }

    companion object {
        fun from(json: JsonValue): MailAttachment? {
            val id = json["id"]?.intValue ?: return null
            return MailAttachment(
                id = id,
                filename = json["filename"]?.stringValue ?: "attachment",
                contentType = json["content_type"]?.stringValue ?: "application/octet-stream",
                sizeBytes = json["size_bytes"]?.intValue ?: 0,
                url = json["url"]?.takeUnless { it.isNull }?.stringValue,
            )
        }
    }
}

/** A file the user picked to attach to a send. Carried as a multipart part;
 *  the server re-detects the type and enforces the size/count caps and is the
 *  sole authority — the client only pre-filters the picker. */
data class MailOutgoingAttachment(
    val id: String = UUID.randomUUID().toString(),
    val filename: String,
    val mimeType: String,
    val data: ByteArray,
) {
    override fun equals(other: Any?): Boolean = other is MailOutgoingAttachment && other.id == id
    override fun hashCode(): Int = id.hashCode()
}

/** One message of `mailbox/thread`. */
data class MailMessage(
    val id: Int,
    val aliasId: Int?,
    val sender: String,
    val recipient: String,
    val subject: String,
    val receivedTime: String,
    val isRead: Boolean,
    val isStarred: Boolean,
    val direction: String,
    val bodyPlain: String,
    val bodyHtml: String,
    val attachments: List<MailAttachment>,
) {
    val isOutbound: Boolean get() = direction == "outbound"

    companion object {
        fun from(json: JsonValue): MailMessage? {
            val id = json["id"]?.intValue ?: return null
            return MailMessage(
                id = id,
                aliasId = json["alias_id"]?.takeUnless { it.isNull }?.intValue,
                sender = json["sender"]?.stringValue ?: "",
                recipient = json["recipient"]?.stringValue ?: "",
                subject = json["subject"]?.stringValue ?: "",
                receivedTime = json["received_time"]?.stringValue ?: "",
                isRead = json["is_read"]?.boolValue ?: true,
                isStarred = json["is_starred"]?.boolValue ?: false,
                direction = json["direction"]?.stringValue ?: "inbound",
                bodyPlain = json["body_plain"]?.stringValue ?: "",
                bodyHtml = json["body_html"]?.stringValue ?: "",
                attachments = (json["attachments"]?.arrayValue ?: emptyList())
                    .mapNotNull { MailAttachment.from(it) },
            )
        }
    }
}

/** The `mailbox/thread` payload: the in-scope messages plus the
 *  thread's current folder/label memberships (ids into the mailbox's
 *  [Mailbox.folders]). */
data class MailThread(
    val messages: List<MailMessage>,
    val folderIds: List<Int>,
) {
    companion object {
        fun from(data: JsonValue?): MailThread? {
            if (data == null) return null
            return MailThread(
                messages = (data["messages"]?.arrayValue ?: emptyList())
                    .mapNotNull { MailMessage.from(it) },
                folderIds = (data["folders"]?.arrayValue ?: emptyList())
                    .mapNotNull { it.intValue },
            )
        }
    }
}

// MARK: - Address + date display helpers

object MailDisplay {
    // The sender-label rules below mirror the web reader's helpers in
    // plugins/mailbox/assets/mailbox_reader.js — one mail surface, one label for the
    // same message. Change them together.

    /** Mail providers where the person is the identity and the domain says nothing:
     *  a bare address here falls back to the local part, not to "Gmail". */
    private val CONSUMER_MAIL_DOMAINS = setOf(
        "gmail", "googlemail", "outlook", "hotmail", "live", "msn",
        "yahoo", "ymail", "aol", "icloud", "me", "mac",
        "proton", "protonmail", "pm", "fastmail", "hey", "zoho",
        "gmx", "web", "mail", "yandex", "qq", "163", "126"
    )

    /** Registry-ish second levels, so example.co.uk yields "example" and not "co". */
    private val REGISTRY_SECOND_LEVELS = setOf("co", "com", "net", "org", "edu", "gov", "ac", "or", "ne")

    /** Mailboxes no person owns. A role address is infrastructure, so its local part is
     *  never the identity — the sending organization is, even at a consumer provider:
     *  no-reply@notify.proton.me is Proton writing to you, not somebody named No-Reply. */
    private val ROLE_LOCAL_PARTS = setOf(
        "noreply", "donotreply", "notify", "notification", "notifications",
        "alert", "alerts", "bounce", "bounces", "postmaster",
        "mailerdaemon", "abuse", "webmaster", "root", "support",
        "help", "info", "billing", "sales", "admin", "contact"
    )

    /** "jeremy.tunnell" → "Jeremy Tunnell", "e-trade" → "E-Trade". */
    fun titleCase(label: String): String {
        val spaced = label.replace(Regex("[._+]+"), " ").replace(Regex("\\s+"), " ").trim()
        val out = StringBuilder()
        var atWordStart = true
        for (ch in spaced) {
            // ASCII-only, matching the web helper's /[a-z]/.
            if (atWordStart && ch in 'a'..'z') out.append(ch.uppercaseChar()) else out.append(ch)
            atWordStart = (ch == ' ' || ch == '-')
        }
        return out.toString()
    }

    /** The host labels left after dropping the public suffix: accounts.google.com →
     *  ["accounts", "google"], mail.example.co.uk → ["mail", "example"]. */
    private fun registrableLabels(host: String): List<String> {
        val parts = host.lowercase().split('.').filter { it.isNotEmpty() }.toMutableList()
        if (parts.size < 2) return parts
        parts.removeAt(parts.size - 1)                                  // the TLD
        if (parts.size > 1 && REGISTRY_SECOND_LEVELS.contains(parts[parts.size - 1])) {
            parts.removeAt(parts.size - 1)                              // a ccTLD's second level
        }
        return parts
    }

    /** The organization label out of a host: accounts.google.com → "google". Taking the
     *  LAST remaining label after the public suffix drops infrastructure subdomains. */
    fun orgLabel(host: String): String {
        val parts = registrableLabels(host)
        if (parts.size < 2) return parts.firstOrNull() ?: ""
        return parts.last()
    }

    /** True when the address sits BELOW a domain rather than at it (notify.proton.me vs
     *  proton.me). A personal mailbox is never at a subdomain of its provider, so this
     *  is what separates a provider's own outbound infrastructure from its users. */
    fun hasSubdomain(host: String): Boolean = registrableLabels(host).size > 1

    /** A role mailbox by name: exact match on the punctuation-stripped local part, or a
     *  no-reply marker anywhere in it (AmericanExpress-no-reply, DOTServicesnoreply). */
    fun isRoleLocalPart(local: String): Boolean {
        val key = local.lowercase().filter { it in 'a'..'z' || it in '0'..'9' }
        if (ROLE_LOCAL_PARTS.contains(key)) return true
        return key.contains("noreply") || key.contains("donotreply")
    }

    /** "Jane Doe <jane@x.com>" → "Jane Doe". With no display name the sending
     *  ORGANIZATION is the identity — hello@fireworks.ai reads as "Fireworks", not
     *  "hello" — except at a consumer mail provider, where the local part is the only
     *  identity there is. That exception holds only for what could actually be a
     *  person's mailbox: a role address, or one below the provider's own domain, is the
     *  company writing. */
    fun senderName(raw: String): String {
        val trimmed = raw.trim()
        if (trimmed.isEmpty()) return "(unknown)"
        val lt = trimmed.indexOf('<')
        if (lt > -1) {
            val name = trimmed.substring(0, lt).trim(' ', '"', '\'')
            if (name.isNotEmpty()) return name
        }
        val addr = address(trimmed)
        val at = addr.lastIndexOf('@')
        if (at < 1) {
            val bare = addr.trim('<', '>', ' ')
            return if (bare.isEmpty()) "(unknown)" else bare
        }
        val local = addr.substring(0, at)
        val host = addr.substring(at + 1)
        val org = orgLabel(host)
        val asPerson = { titleCase(local).ifEmpty { local } }
        if (org.isEmpty()) return asPerson()
        val personal = CONSUMER_MAIL_DOMAINS.contains(org) &&
            !hasSubdomain(host) &&
            !isRoleLocalPart(local)
        return if (personal) asPerson() else titleCase(org)
    }

    /** The bare address inside an RFC-style sender string. */
    fun address(raw: String): String {
        val lt = raw.indexOf('<')
        val gt = raw.indexOf('>')
        if (lt > -1 && gt > lt) return raw.substring(lt + 1, gt)
        return raw.trim()
    }

    private fun dbFormatter(): SimpleDateFormat =
        SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.US).apply {
            timeZone = TimeZone.getTimeZone("UTC")
            isLenient = false
        }

    /** Server times are UTC "yyyy-MM-dd HH:mm:ss(.ffffff)". */
    fun date(dbTime: String): Date? {
        if (dbTime.length < 19) return null
        return try {
            dbFormatter().parse(dbTime.substring(0, 19))
        } catch (e: Exception) {
            null
        }
    }

    /** Gmail-style list stamp: time today, "Jul 3" this year, else "7/3/25". */
    fun listStamp(dbTime: String, now: Date = Date()): String {
        val date = date(dbTime) ?: return ""
        val cal = Calendar.getInstance()
        val dateCal = Calendar.getInstance().apply { time = date }
        val nowCal = Calendar.getInstance().apply { time = now }
        cal.time = date
        val sameDay = dateCal.get(Calendar.YEAR) == nowCal.get(Calendar.YEAR) &&
            dateCal.get(Calendar.DAY_OF_YEAR) == nowCal.get(Calendar.DAY_OF_YEAR)
        return when {
            sameDay -> DateFormat.getTimeInstance(DateFormat.SHORT).format(date)
            dateCal.get(Calendar.YEAR) == nowCal.get(Calendar.YEAR) ->
                SimpleDateFormat("MMM d", Locale.getDefault()).format(date)
            else -> DateFormat.getDateInstance(DateFormat.SHORT).format(date)
        }
    }

    /** Header stamp inside a thread: "Jul 3, 2026, 9:41 AM". */
    fun messageStamp(dbTime: String): String {
        val date = date(dbTime) ?: return ""
        return DateFormat.getDateTimeInstance(DateFormat.MEDIUM, DateFormat.SHORT).format(date)
    }

    /** Stable avatar hue for a sender (Gmail-style colored initial circle).
     *  FNV-1a over the bare lowercased address — the same bucketing as iOS. */
    fun avatarColorIndex(raw: String, paletteSize: Int): Int {
        val addr = address(raw).lowercase()
        var hash = 2166136261L.toInt()
        for (byte in addr.toByteArray(Charsets.UTF_8)) {
            hash = (hash xor (byte.toInt() and 0xFF)) * 16777619
        }
        val size = maxOf(paletteSize, 1)
        return ((hash.toLong() and 0xFFFFFFFFL) % size).toInt()
    }
}
