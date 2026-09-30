package com.getjoinery.mail

import android.content.ActivityNotFoundException
import android.content.Context
import android.content.Intent
import androidx.core.content.FileProvider
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import java.io.File
import java.util.UUID

/**
 * Where an opened attachment lives while the system viewer or share sheet has
 * it (B4): the app's private cache, one random directory per open, deleted
 * when the person comes back to the app, at sign-out, and on the next launch
 * for anything a crash left behind. For an end-to-end message these are the
 * only plaintext copies at rest, so they must not linger.
 */
object MailFiles {
    private const val DIR = "mail_open"

    private fun root(context: Context) = File(context.cacheDir, DIR)

    /** Write [bytes] under a fresh private directory, named for the receiving app. */
    suspend fun stage(context: Context, filename: String, bytes: ByteArray): File = withContext(Dispatchers.IO) {
        val dir = File(root(context), UUID.randomUUID().toString()).apply { mkdirs() }
        val safe = filename.replace(Regex("[/\\\\\\u0000]"), "_").trim().ifEmpty { "attachment" }.take(120)
        val target = File(dir, safe)
        target.outputStream().use { it.write(bytes) }
        // Owner-only, whatever the process umask says.
        target.setReadable(false, false); target.setReadable(true, true)
        target.setWritable(false, false); target.setWritable(true, true)
        target
    }

    /** Remove every staged file (the viewer returned, sign-out, launch). */
    fun sweep(context: Context) {
        root(context).listFiles()?.forEach { dir ->
            dir.walkBottomUp().forEach { f ->
                if (f.isFile) try { f.writeBytes(ByteArray(minOf(f.length(), 64L * 1024 * 1024).toInt())) } catch (_: Exception) {}
                f.delete()
            }
        }
    }

    /** Hand a staged file to the system viewer, falling back to a share. */
    fun open(context: Context, file: File, filename: String, contentType: String) {
        val uri = FileProvider.getUriForFile(context, "${context.packageName}.joinerymail.files", file)
        val type = contentType.ifEmpty { "application/octet-stream" }
        val view = Intent(Intent.ACTION_VIEW)
            .setDataAndType(uri, type)
            .addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
        try {
            context.startActivity(Intent.createChooser(view, filename))
        } catch (e: ActivityNotFoundException) {
            val send = Intent(Intent.ACTION_SEND)
                .setType(type)
                .putExtra(Intent.EXTRA_STREAM, uri)
                .addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
            context.startActivity(Intent.createChooser(send, filename))
        }
    }
}
