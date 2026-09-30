package com.getjoinery.mail.notify

import android.Manifest
import android.os.Build
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import com.getjoinery.mail.MailboxStore

/** Which mailboxes notify on this phone (§ R15). Quiet hours are the phone's
 *  own Do Not Disturb. The first switch turned on asks for the permission. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun NotificationSettingsSheet(store: MailboxStore, onDismiss: () -> Unit) {
    val context = LocalContext.current
    val prefs = remember { PollerPrefs(context) }
    val boxes = store.home?.mailboxes ?: emptyList()
    var enabled by remember { mutableStateOf(prefs.enabled ?: NewMailCheck.defaultEnabled(boxes)) }
    var permitted by remember { mutableStateOf(MailNotifications.permitted(context)) }
    val ask = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { granted -> permitted = granted }

    ModalBottomSheet(onDismissRequest = onDismiss, modifier = Modifier.testTag("mail_notify_sheet")) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 24.dp).padding(bottom = 32.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Text("New-mail notifications", style = MaterialTheme.typography.titleMedium)
            Text(
                "New mail shows when your phone next checks, which it decides. End-to-end encrypted and private " +
                    "mailboxes show only that a message arrived, never who from or about what.",
                style = MaterialTheme.typography.bodySmall,
            )
            if (!permitted) {
                Text("Notifications are off for this app in your phone's settings.",
                    style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.error)
            }
            boxes.forEach { box ->
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(box.address, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f))
                    Switch(
                        checked = box.aliasId in enabled,
                        onCheckedChange = { on ->
                            enabled = if (on) enabled + box.aliasId else enabled - box.aliasId
                            prefs.enabled = enabled
                            if (on && !permitted && Build.VERSION.SDK_INT >= 33) ask.launch(Manifest.permission.POST_NOTIFICATIONS)
                        },
                        modifier = Modifier.testTag("mail_notify_${box.aliasId}"),
                    )
                }
            }
        }
    }
}
