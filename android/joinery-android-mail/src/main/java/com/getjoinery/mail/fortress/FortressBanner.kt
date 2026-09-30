package com.getjoinery.mail.fortress

import android.content.ClipData
import android.content.ClipboardManager
import android.content.Context
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.text.selection.SelectionContainer
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.Lock
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.getjoinery.android.JoineryApiError
import com.getjoinery.android.vault.BiometricGate
import com.getjoinery.android.vault.DeviceEnrollment
import com.getjoinery.android.vault.SecretStoreLockedException
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/**
 * The one banner a Fortress mailbox shows when this phone cannot read it
 * (specs/fortress_mobile_apps.md § R10): without the key, "hand this phone the
 * key" with Enroll; with the key locked, Unlock.
 */
@Composable
fun FortressBanner(fortress: FortressMail, onUnlocked: () -> Unit) {
    @Suppress("UNUSED_VARIABLE") val version = fortress.keyVersion
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var showEnroll by remember { mutableStateOf(false) }
    var message by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }

    if (fortress.isOpen) return
    val holds = fortress.holdsKey

    Surface(
        color = MaterialTheme.colorScheme.secondaryContainer,
        modifier = Modifier.fillMaxWidth().padding(horizontal = 12.dp, vertical = 6.dp).testTag("mail_fortress_banner"),
        shape = MaterialTheme.shapes.medium,
    ) {
        Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.Top) {
                Icon(Icons.Outlined.Lock, contentDescription = null, modifier = Modifier.size(20.dp))
                Text(
                    if (holds) "Your end-to-end encrypted mail is locked on this phone."
                    else "This mailbox is end-to-end encrypted. Hand this phone the key from a computer where your mail is open.",
                    style = MaterialTheme.typography.bodyMedium,
                    modifier = Modifier.weight(1f),
                )
            }
            message?.let {
                Text(it, style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.error,
                    modifier = Modifier.testTag("mail_fortress_banner_message"))
            }
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                if (holds) {
                    Button(
                        enabled = !busy,
                        onClick = {
                            val activity = BiometricGate.activity(context) ?: return@Button
                            busy = true
                            scope.launch {
                                message = fortress.unlock(activity)
                                busy = false
                                if (message == null) onUnlocked()
                            }
                        },
                        modifier = Modifier.testTag("mail_fortress_unlock"),
                    ) { Text("Unlock") }
                } else {
                    Button(onClick = { showEnroll = true }, modifier = Modifier.testTag("mail_fortress_enroll")) {
                        Text("Enroll")
                    }
                }
            }
        }
    }

    if (showEnroll) {
        EnrollSheet(fortress, onDismiss = { showEnroll = false }, onDone = {
            showEnroll = false
            onUnlocked()
        })
    }
}

/**
 * Enroll: show the code and the page to open on a computer, wait for the
 * approval, open the handed-over key behind the biometric gate.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun EnrollSheet(fortress: FortressMail, onDismiss: () -> Unit, onDone: () -> Unit) {
    val context = LocalContext.current
    var ticket by remember { mutableStateOf<DeviceEnrollment.Ticket?>(null) }
    var status by remember { mutableStateOf("Preparing…") }
    var failure by remember { mutableStateOf<String?>(null) }
    var attempt by remember { mutableStateOf(0) }

    LaunchedEffect(attempt) {
        failure = null
        ticket = null
        val activity = BiometricGate.activity(context)
        if (activity == null) {
            failure = "This screen cannot ask for your fingerprint or face."
            return@LaunchedEffect
        }
        val enrollment = fortress.enrollment()
        // The device key is made (or read) inside the secret store, which one
        // biometric prompt opens (its key authorizes nothing else).
        if (!fortress.keys.storeUnlocked) {
            status = "Confirm with your fingerprint or face to set up this phone."
            val problem = BiometricGate.unlock(activity, fortress.keys, "This phone gets its own key", "Set up encrypted mail")
            if (problem != null) {
                failure = problem
                return@LaunchedEffect
            }
        }
        val t = try {
            enrollment.begin()
        } catch (e: Exception) {
            failure = (e as? JoineryApiError)?.displayMessage ?: (e.message ?: "Could not start.")
            return@LaunchedEffect
        }
        ticket = t
        status = "Waiting for approval…"
        while (true) {
            delay(t.pollAfterSeconds * 1000L)
            val poll = try { enrollment.poll(t) } catch (e: Exception) { continue }
            when (poll) {
                is DeviceEnrollment.Poll.Pending -> continue
                is DeviceEnrollment.Poll.Denied -> { failure = "The request was denied on your computer."; return@LaunchedEffect }
                is DeviceEnrollment.Poll.Over -> { failure = poll.message; return@LaunchedEffect }
                is DeviceEnrollment.Poll.Approved -> {
                    if (!poll.sealedKeys.containsKey(FortressOpener.SCOPE)) {
                        failure = "The approval did not include your mail key. Start again and tick Mail on your computer."
                        return@LaunchedEffect
                    }
                    status = "Opening the key…"
                    try {
                        enrollment.accept(poll)
                    } catch (e: SecretStoreLockedException) {
                        // The store shut while waiting (the app went away too long).
                        val problem = BiometricGate.unlock(activity, fortress.keys, "Store your mail key on this phone", "Finish setting up encrypted mail")
                        if (problem != null) { failure = "$problem Start again."; return@LaunchedEffect }
                        try {
                            enrollment.accept(poll)
                        } catch (e2: Exception) {
                            failure = e2.message ?: "The key could not be stored."
                            return@LaunchedEffect
                        }
                    } catch (e: Exception) {
                        failure = e.message ?: "The key could not be opened on this phone."
                        return@LaunchedEffect
                    }
                    fortress.checkHeld()
                    onDone()
                    return@LaunchedEffect
                }
            }
        }
    }

    ModalBottomSheet(onDismissRequest = onDismiss, modifier = Modifier.testTag("mail_fortress_enroll_sheet")) {
        Column(
            Modifier.fillMaxWidth().padding(horizontal = 24.dp).padding(bottom = 32.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            Text("Hand this phone your mail key", style = MaterialTheme.typography.titleMedium)
            val t = ticket
            if (t != null) {
                Text("On a computer where your mail is open, go to:", style = MaterialTheme.typography.bodyMedium)
                SelectionContainer {
                    Text(t.verifyUrl, style = MaterialTheme.typography.bodyMedium, fontFamily = FontFamily.Monospace,
                        modifier = Modifier.testTag("mail_fortress_enroll_url"))
                }
                Text("and enter this code:", style = MaterialTheme.typography.bodyMedium)
                Text(
                    t.linkCode,
                    fontSize = 32.sp,
                    fontWeight = FontWeight.SemiBold,
                    fontFamily = FontFamily.Monospace,
                    modifier = Modifier.testTag("mail_fortress_enroll_code"),
                )
                OutlinedButton(onClick = { copy(context, t.verifyUrl) }) { Text("Copy link") }
            }
            val f = failure
            if (f != null) {
                Text(f, color = MaterialTheme.colorScheme.error, modifier = Modifier.testTag("mail_fortress_enroll_error"))
                Button(onClick = { attempt += 1 }) { Text("Start again") }
            } else {
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    CircularProgressIndicator(Modifier.size(18.dp), strokeWidth = 2.dp)
                    Text(status, style = MaterialTheme.typography.bodyMedium, modifier = Modifier.testTag("mail_fortress_enroll_status"))
                }
            }
            Spacer(Modifier.size(4.dp))
            Text(
                "The key stays behind this phone's fingerprint or face lock. A stolen phone opens nothing; " +
                    "a phone you unlock and hand to someone can read your mail.",
                style = MaterialTheme.typography.labelMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }
    }
}

private fun copy(context: Context, text: String) {
    val cm = context.getSystemService(Context.CLIPBOARD_SERVICE) as? ClipboardManager ?: return
    cm.setPrimaryClip(ClipData.newPlainText("Link", text))
}
