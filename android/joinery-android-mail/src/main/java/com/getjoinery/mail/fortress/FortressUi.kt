package com.getjoinery.mail.fortress

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.Key
import androidx.compose.material.icons.outlined.Lock
import androidx.compose.material.icons.outlined.ManageSearch
import androidx.compose.material.icons.outlined.Notifications
import androidx.compose.material.icons.outlined.Psychology
import androidx.compose.material.icons.outlined.SearchOff
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
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
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import com.getjoinery.android.JsonValue
import com.getjoinery.android.vault.BiometricGate
import com.getjoinery.mail.MailboxStore
import com.getjoinery.mail.notify.NotificationSettingsSheet
import com.getjoinery.mail.search.MailSearchIndex
import kotlinx.coroutines.launch

/** The progress lines under the banner: arrivals being opened, the search
 *  index building, the AI drain — and the relay alarm when one is raised. */
@Composable
fun FortressProgress(work: FortressWork, search: MailSearchIndex) {
    val lines = listOfNotNull(work.status, search.status)
    if (lines.isNotEmpty()) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 2.dp)) {
            lines.forEach {
                Text(it, style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.onSurfaceVariant,
                    modifier = Modifier.testTag("mail_fortress_progress"))
            }
        }
    }
    work.alarm?.let { a ->
        AlertDialog(
            onDismissRequest = { work.alarm = null },
            title = { Text("Mail arriving at your relay is not being sealed to your key") },
            text = {
                Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    Text("${a.address}: ${RelayPinCheck.REASONS[a.reason] ?: RelayPinCheck.REASONS["unreadable"]} " +
                        "Until this is settled, mail to this address may be readable by whoever runs this server.")
                    if (a.expected != null || a.reported != null) {
                        Text(if (a.reason == "key") "Your vault's key" else "The relay your devices trust", style = MaterialTheme.typography.labelMedium)
                        Text(RelayPinCheck.fingerprint(a.expected), fontFamily = FontFamily.Monospace)
                        Text(if (a.reason == "key") "The key the relay seals to" else "The relay answering now", style = MaterialTheme.typography.labelMedium)
                        Text(RelayPinCheck.fingerprint(a.reported), fontFamily = FontFamily.Monospace)
                    }
                    Text("To trust a new relay, open your mail on a computer: it asks you to confirm with your passkey.",
                        style = MaterialTheme.typography.bodySmall)
                }
            },
            confirmButton = { TextButton(onClick = { work.alarm = null }) { Text("Close") } },
            modifier = Modifier.testTag("mail_relay_alarm"),
        )
    }
}

/** The mailbox menu's end-to-end section. */
@Composable
fun FortressMenuItems(store: MailboxStore, fortress: FortressMail, search: MailSearchIndex, close: () -> Unit) {
    val scope = rememberCoroutineScope()
    var showAi by remember { mutableStateOf(false) }
    var showNotify by remember { mutableStateOf(false) }
    var confirmForget by remember { mutableStateOf(false) }

    HorizontalDivider()
    DropdownMenuItem(
        text = { Text("New-mail notifications") },
        leadingIcon = { Icon(Icons.Outlined.Notifications, contentDescription = null) },
        onClick = { showNotify = true },
        modifier = Modifier.testTag("mail_menu_notifications"),
    )
    if (store.home?.mailboxes?.any { it.isFortress } == true && fortress.holdsKey) {
        DropdownMenuItem(
            text = { Text("Rebuild search on this phone") },
            leadingIcon = { Icon(Icons.Outlined.ManageSearch, contentDescription = null) },
            enabled = fortress.isOpen,
            onClick = { close(); scope.launch { search.rebuild() } },
            modifier = Modifier.testTag("mail_menu_search_rebuild"),
        )
        DropdownMenuItem(
            text = { Text("Remove search from this phone") },
            leadingIcon = { Icon(Icons.Outlined.SearchOff, contentDescription = null) },
            onClick = { close(); scope.launch { search.remove() } },
            modifier = Modifier.testTag("mail_menu_search_remove"),
        )
        DropdownMenuItem(
            text = { Text("AI on your own model") },
            leadingIcon = { Icon(Icons.Outlined.Psychology, contentDescription = null) },
            onClick = { showAi = true },
            modifier = Modifier.testTag("mail_menu_ai"),
        )
        if (fortress.isOpen) {
            DropdownMenuItem(
                text = { Text("Lock encrypted mail") },
                leadingIcon = { Icon(Icons.Outlined.Lock, contentDescription = null) },
                onClick = { close(); fortress.lock() },
                modifier = Modifier.testTag("mail_menu_lock"),
            )
        }
        DropdownMenuItem(
            text = { Text("Remove keys from this phone") },
            leadingIcon = { Icon(Icons.Outlined.Key, contentDescription = null) },
            onClick = { confirmForget = true },
            modifier = Modifier.testTag("mail_menu_forget_keys"),
        )
    }

    if (showAi) {
        DeviceAiSheet(store, fortress, onDismiss = { showAi = false; close() })
    }
    if (showNotify) {
        NotificationSettingsSheet(store, onDismiss = { showNotify = false; close() })
    }
    if (confirmForget) {
        AlertDialog(
            onDismissRequest = { confirmForget = false },
            title = { Text("Remove keys from this phone?") },
            text = { Text("This phone forgets your mail key and its own device key. To read end-to-end encrypted mail here again, hand it the key from a computer.") },
            confirmButton = {
                TextButton(onClick = {
                    confirmForget = false
                    close()
                    scope.launch {
                        search.remove()
                        fortress.forgetEverything()
                        store.reopen()
                    }
                }, modifier = Modifier.testTag("mail_forget_keys_confirm")) { Text("Remove") }
            },
            dismissButton = { TextButton(onClick = { confirmForget = false }) { Text("Cancel") } },
        )
    }
}

/** The endpoint settings: path, key, model, Wi-Fi only, Test (§ R13). */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun DeviceAiSheet(store: MailboxStore, fortress: FortressMail, onDismiss: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val work = remember { FortressWork.get(context, fortress.client, fortress) }
    val settings = work.aiSettings
    val saved = remember { settings.load() }
    var path by remember { mutableStateOf(saved?.path ?: "/v1") }
    var key by remember { mutableStateOf(saved?.key ?: "") }
    var model by remember { mutableStateOf(saved?.model ?: "") }
    var wifiOnly by remember { mutableStateOf(settings.wifiOnly) }
    var origin by remember { mutableStateOf<String?>(null) }
    var loaded by remember { mutableStateOf(false) }
    var message by remember { mutableStateOf<String?>(null) }
    val mailbox = store.home?.mailboxes?.firstOrNull { it.isFortress }?.address ?: ""

    LaunchedEffect(Unit) {
        origin = try {
            fortress.client.submitAction("mailbox/ai_device_recipes", JsonValue.obj("mailbox" to JsonValue.Str(mailbox)))["data"]
                ?.get("device_ai_origin")?.takeUnless { it.isNull }?.stringValue
        } catch (e: Exception) { null }
        loaded = true
    }

    ModalBottomSheet(onDismissRequest = onDismiss, modifier = Modifier.testTag("mail_ai_sheet")) {
        Column(
            Modifier.fillMaxWidth().padding(horizontal = 24.dp).padding(bottom = 32.dp).verticalScroll(rememberScrollState()),
            verticalArrangement = Arrangement.spacedBy(10.dp),
        ) {
            Text("AI on your own model", style = MaterialTheme.typography.titleMedium)
            Text(
                "Summaries and the security scan for end-to-end encrypted mail are written by a model you own. " +
                    "This phone sends the opened message to it, then seals the answer; the server never sees either.",
                style = MaterialTheme.typography.bodySmall,
            )
            val o = origin
            if (!loaded) {
                Text("Loading…")
            } else if (o == null) {
                Text(
                    "No model is registered for your account. Register where your model lives on the Email settings page on a computer.",
                    color = MaterialTheme.colorScheme.error,
                    modifier = Modifier.testTag("mail_ai_no_origin"),
                )
            } else {
                Text("Your model host: $o", fontFamily = FontFamily.Monospace, style = MaterialTheme.typography.bodySmall)
                OutlinedTextField(path, { path = it }, label = { Text("Path (e.g. /v1)") }, singleLine = true,
                    modifier = Modifier.fillMaxWidth().testTag("mail_ai_path"))
                OutlinedTextField(model, { model = it }, label = { Text("Model name") }, singleLine = true,
                    modifier = Modifier.fillMaxWidth().testTag("mail_ai_model"))
                OutlinedTextField(key, { key = it }, label = { Text("API key (blank for none)") }, singleLine = true,
                    visualTransformation = PasswordVisualTransformation(),
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password),
                    modifier = Modifier.fillMaxWidth().testTag("mail_ai_key"))
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text("Wi-Fi only", modifier = Modifier.weight(1f))
                    Switch(wifiOnly, { wifiOnly = it; settings.wifiOnly = it }, modifier = Modifier.testTag("mail_ai_wifi"))
                }
                Text("An Ollama on a home computer is reachable only on the same network or over a tailnet.",
                    style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    OutlinedButton(onClick = {
                        message = "Testing…"
                        scope.launch { message = settings.test(fortress.client, o, path, key, model, mailbox) }
                    }, enabled = model.isNotBlank(), modifier = Modifier.testTag("mail_ai_test")) { Text("Test") }
                    Button(onClick = {
                        if (DeviceAiSettings.normalizePath(path) == null) { message = "That path would leave your model host."; return@Button }
                        val activity = BiometricGate.activity(context) ?: return@Button
                        scope.launch {
                            // The key is a secret: stored only in the secret store,
                            // which the fingerprint or face opens.
                            val problem = BiometricGate.unlock(activity, fortress.keys, "Kept behind your fingerprint or face lock", "Save your model's key")
                            if (problem != null) { message = "Not saved: $problem"; return@launch }
                            message = try {
                                settings.save(path, key, model)
                                "Saved. AI runs while your mailbox is open."
                            } catch (e: Exception) {
                                "Not saved: ${e.message}"
                            }
                        }
                    }, enabled = model.isNotBlank() && fortress.isOpen, modifier = Modifier.testTag("mail_ai_save")) { Text("Save") }
                }
            }
            message?.let { Text(it, style = MaterialTheme.typography.bodySmall, modifier = Modifier.testTag("mail_ai_message")) }
        }
    }
}
