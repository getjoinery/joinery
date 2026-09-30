package com.getjoinery.mail.notify

import android.Manifest
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.pm.PackageManager
import android.os.Build
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import androidx.core.content.ContextCompat
import androidx.work.Constraints
import androidx.work.CoroutineWorker
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.NetworkType
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import com.getjoinery.android.ApiClient
import com.getjoinery.android.EncryptedCredentialStore
import com.getjoinery.android.JoineryConfig
import com.getjoinery.mail.MailApi
import com.getjoinery.mail.MailDisplay
import com.getjoinery.mail.MailView
import com.getjoinery.mail.Mailbox
import java.util.concurrent.TimeUnit

/**
 * The new-mail decision (specs/fortress_mobile_apps.md § R15), pure so it is
 * unit-tested: `mailboxes` gives each mailbox `newest_unread_id`; a mailbox
 * the person turned on notifies when that id is above the high-water mark it
 * last notified for. The mark only rises, so reading mail elsewhere (which
 * lowers or clears the id) never makes a later arrival look old. The first
 * check after sign-in only records marks.
 */
object NewMailCheck {
    data class Box(val aliasId: Int, val address: String, val level: String, val newestUnreadId: Int?, val unread: Int)
    data class Notice(val aliasId: Int, val address: String, val level: String, val newestId: Int)
    data class Result(val notices: List<Notice>, val marks: Map<Int, Int>, val badge: Int)

    fun decide(boxes: List<Box>, enabled: Set<Int>, marks: Map<Int, Int>, firstCheck: Boolean): Result {
        val next = HashMap(marks)
        val out = ArrayList<Notice>()
        var badge = 0
        for (b in boxes) {
            if (b.aliasId !in enabled) continue
            badge += b.unread
            val newest = b.newestUnreadId ?: continue
            val mark = marks[b.aliasId] ?: 0
            if (newest > mark) {
                if (!firstCheck) out.add(Notice(b.aliasId, b.address, b.level, newest))
                next[b.aliasId] = newest
            }
        }
        return Result(out, next, badge)
    }

    enum class OnError { RETRY, STOP }

    /** A revoked or refused key stops the check for good; anything else
     *  (offline, a server hiccup) is tried again later. */
    fun onError(e: Exception): OnError = when (e) {
        is com.getjoinery.android.JoineryApiError.Authentication -> OnError.STOP
        is com.getjoinery.android.JoineryApiError.UpgradeRequired -> OnError.STOP
        else -> OnError.RETRY
    }

    /** Which mailboxes notify by default: the ones the person is a member of. */
    fun defaultEnabled(boxes: List<Mailbox>): Set<Int> = boxes.filter { it.own }.map { it.aliasId }.toSet()
}

/** Where the poller keeps its marks, choices and the app's connection facts
 *  (plain prefs: nothing secret — the session key stays in its own store). */
class PollerPrefs(context: Context) {
    private val p = context.applicationContext.getSharedPreferences("joinery.mailpoller", Context.MODE_PRIVATE)

    var marks: Map<Int, Int>
        get() = (p.getString("marks", "") ?: "").split(',').mapNotNull {
            val kv = it.split(':'); if (kv.size == 2) kv[0].toIntOrNull()?.let { k -> kv[1].toIntOrNull()?.let { v -> k to v } } else null
        }.toMap()
        set(value) { p.edit().putString("marks", value.entries.joinToString(",") { "${it.key}:${it.value}" }).apply() }

    var initialized: Boolean
        get() = p.getBoolean("initialized", false)
        set(value) { p.edit().putBoolean("initialized", value).apply() }

    /** Null until the person chose; then exactly the mailboxes turned on. */
    var enabled: Set<Int>?
        get() = p.getString("enabled", null)?.split(',')?.mapNotNull { it.toIntOrNull() }?.toSet()
        set(value) { p.edit().putString("enabled", value?.joinToString(",")).apply() }

    fun connection(): Triple<JoineryConfig, String, Boolean>? {
        val base = p.getString("base_url", null) ?: return null
        val app = p.getString("client_app", null) ?: return null
        val version = p.getString("client_version", null) ?: "0"
        val store = p.getString("store_file", null) ?: return null
        return Triple(JoineryConfig(baseUrl = base, clientApp = app, clientVersion = version, appName = ""), store, true)
    }

    fun saveConnection(config: JoineryConfig, storeFile: String) {
        p.edit().putString("base_url", config.baseUrl).putString("client_app", config.clientApp)
            .putString("client_version", config.clientVersion).putString("store_file", storeFile).apply()
    }

    fun clear() {
        p.edit().clear().apply()
    }
}

/**
 * The scheduled background check (WorkManager periodic work, 15 minutes at
 * best, network required). It rides the stored session key, asks
 * `mailboxes`, and posts one notification per mailbox with new mail. For a
 * Standard mailbox it fetches the newest thread and shows its sender and
 * subject; Private and Fortress show the mailbox only — the session key opens
 * no server window, and the Fortress key is never held in the background.
 */
class MailPollWorker(context: Context, params: WorkerParameters) : CoroutineWorker(context, params) {
    override suspend fun doWork(): Result {
        val prefs = PollerPrefs(applicationContext)
        val (config, storeFile, _) = prefs.connection() ?: return Result.success()
        val credentials = EncryptedCredentialStore(applicationContext, storeFile).loadCredentials() ?: return Result.success()
        val client = ApiClient(config).apply { this.credentials = credentials }
        val api = MailApi(client)
        val home = try {
            api.mailboxes()
        } catch (e: Exception) {
            return when (NewMailCheck.onError(e)) {
                NewMailCheck.OnError.STOP -> {
                    // The key was revoked (the Security page, a password
                    // change): stop checking with it. The app signs out at its
                    // next launch.
                    MailPoller.cancel(applicationContext)
                    Result.success()
                }
                NewMailCheck.OnError.RETRY -> Result.retry()
            }
        }
        val boxes = home.mailboxes.map { NewMailCheck.Box(it.aliasId, it.address, it.securityLevel, it.newestUnreadId, it.unread) }
        val enabled = prefs.enabled ?: NewMailCheck.defaultEnabled(home.mailboxes)
        val result = NewMailCheck.decide(boxes, enabled, prefs.marks, firstCheck = !prefs.initialized)
        prefs.marks = result.marks
        prefs.initialized = true
        for (n in result.notices) {
            var title = "New message in ${n.address}"
            var text: String? = null
            if (n.level == "standard") {
                try {
                    val newest = api.threadList(n.aliasId, MailView.INBOX, null, "", 1).threads.firstOrNull()
                    if (newest != null && newest.sealed == null) {
                        title = MailDisplay.senderName(newest.sender)
                        text = newest.subject.ifEmpty { "(no subject)" }
                    }
                } catch (_: Exception) {}
            }
            MailNotifications.post(applicationContext, n.aliasId, title, text ?: n.address, result.badge)
            // Ids only: which mailbox, and the message the notice is for.
            android.util.Log.i("JoineryMailPoll", "notified alias=${n.aliasId} newest=${n.newestId}")
        }
        return Result.success()
    }
}

object MailNotifications {
    const val CHANNEL = "joinery_new_mail"

    fun ensureChannel(context: Context) {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return
        val nm = context.getSystemService(NotificationManager::class.java) ?: return
        if (nm.getNotificationChannel(CHANNEL) != null) return
        nm.createNotificationChannel(NotificationChannel(CHANNEL, "New mail", NotificationManager.IMPORTANCE_DEFAULT).apply {
            description = "A new message in a mailbox you turned on"
            setShowBadge(true)
        })
    }

    fun permitted(context: Context): Boolean =
        Build.VERSION.SDK_INT < 33 ||
            ContextCompat.checkSelfPermission(context, Manifest.permission.POST_NOTIFICATIONS) == PackageManager.PERMISSION_GRANTED

    fun post(context: Context, aliasId: Int, title: String, text: String, badge: Int) {
        if (!permitted(context)) return
        ensureChannel(context)
        val launch = context.packageManager.getLaunchIntentForPackage(context.packageName)?.apply {
            putExtra(EXTRA_ALIAS, aliasId)
            addFlags(android.content.Intent.FLAG_ACTIVITY_NEW_TASK or android.content.Intent.FLAG_ACTIVITY_CLEAR_TOP)
        }
        val pending = launch?.let {
            PendingIntent.getActivity(context, aliasId, it, PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE)
        }
        val n = NotificationCompat.Builder(context, CHANNEL)
            .setSmallIcon(android.R.drawable.ic_dialog_email)
            .setContentTitle(title)
            .setContentText(text)
            .setNumber(badge)
            .setAutoCancel(true)
            .setContentIntent(pending)
            .setCategory(NotificationCompat.CATEGORY_EMAIL)
            .build()
        try {
            NotificationManagerCompat.from(context).notify(NOTIFY_BASE + aliasId, n)
        } catch (_: SecurityException) {
        }
    }

    const val EXTRA_ALIAS = "joinery.mail.alias_id"
    private const val NOTIFY_BASE = 40_000
}

object MailPoller {
    private const val WORK = "joinery-mail-poll"

    fun schedule(context: Context, client: ApiClient, storeFile: String?) {
        storeFile ?: return
        val prefs = PollerPrefs(context)
        prefs.saveConnection(client.config, storeFile)
        val request = PeriodicWorkRequestBuilder<MailPollWorker>(15, TimeUnit.MINUTES)
            .setConstraints(Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build())
            .build()
        WorkManager.getInstance(context.applicationContext)
            .enqueueUniquePeriodicWork(WORK, ExistingPeriodicWorkPolicy.KEEP, request)
    }

    /** Sign-out and 401: no more checks, and the marks go too. */
    fun cancel(context: Context) {
        WorkManager.getInstance(context.applicationContext).cancelUniqueWork(WORK)
        PollerPrefs(context).clear()
        NotificationManagerCompat.from(context).cancelAll()
    }
}
