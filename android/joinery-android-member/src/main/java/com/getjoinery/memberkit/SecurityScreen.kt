package com.getjoinery.memberkit

import android.graphics.Bitmap
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.KeyboardArrowRight
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Surface
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
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import com.getjoinery.android.ApiClient
import com.getjoinery.android.WebSessionCoordinator
import com.google.zxing.BarcodeFormat
import com.google.zxing.EncodeHintType
import com.google.zxing.qrcode.QRCodeWriter
import com.google.zxing.qrcode.decoder.ErrorCorrectionLevel
import kotlinx.coroutines.launch

/**
 * App sessions + two-factor authentication, natively. Passkeys and the Sealed
 * Vault stay web-managed here — WebView cannot expose platform WebAuthn, so
 * native passkey/vault management is a separate future spec. Revoking the
 * session that made the request signs the app out through the same path a
 * server-side 401 uses (SecurityStore.revoke → reload → 401 → core sign-out).
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun SecurityScreen(client: ApiClient, web: WebSessionCoordinator? = null, onBack: (() -> Unit)? = null) {
    val store = remember { SecurityStore(SecurityApi(client)) }
    val scope = rememberCoroutineScope()
    var pendingRevoke by remember { mutableStateOf<AppSessionRow?>(null) }
    var showRevokeAll by remember { mutableStateOf(false) }
    var showDisable by remember { mutableStateOf(false) }
    var regeneratedCodes by remember { mutableStateOf<List<String>?>(null) }
    var showManageWeb by remember { mutableStateOf(false) }

    LaunchedEffect(Unit) {
        if (store.phase is MemberPhase.Loading) store.initialLoad()
    }
    MemberResumeRefresh(store.phase is MemberPhase.Loading) { scope.launch { store.reload() } }

    // Passkeys and the Sealed Vault are web-managed; that page can change the
    // passkey count / vault flag, so returning from it reloads the overview.
    if (showManageWeb) {
        MemberWebDetail("Security", "/profile/security", client, web, onBack = {
            showManageWeb = false
            scope.launch { store.reload() }
        })
        return
    }

    Scaffold(topBar = { MemberTopBar("Security", onBack) }) { padding ->
        when (val phase = store.phase) {
            is MemberPhase.Loading ->
                Box(Modifier.fillMaxSize().padding(padding), contentAlignment = Alignment.Center) {
                    CircularProgressIndicator(Modifier.testTag("member_security_loading"))
                }
            is MemberPhase.Failed ->
                RetryBox(phase.message, "member_security_error", "member_security_retry", Modifier.padding(padding)) {
                    scope.launch { store.initialLoad() }
                }
            is MemberPhase.Loaded ->
                store.overview?.let { overview ->
                    MemberPullRefresh(onRefresh = { store.reload() }, Modifier.padding(padding)) {
                        SecurityList(
                            overview = overview,
                            showManageWeb = web != null,
                            onEnable = { scope.launch { store.startSetup() } },
                            onDisable = { showDisable = true },
                            onRegenerate = {
                                scope.launch {
                                    store.regenerateBackupCodes()?.let { regeneratedCodes = it }
                                }
                            },
                            onRevoke = { pendingRevoke = it },
                            onRevokeAll = { showRevokeAll = true },
                            onManageWeb = { showManageWeb = true },
                        )
                    }
                }
        }
    }

    // TOTP enable flow (QR + code → backup codes), driven by the store's setup phase.
    when (val setup = store.setupPhase) {
        is SecurityStore.SetupPhase.AwaitingCode ->
            TotpSetupSheet(
                provisioningUri = setup.provisioningUri,
                error = store.setupError,
                isBusy = store.isBusy,
                onConfirm = { code -> scope.launch { store.confirmSetup(code) } },
                onCancel = { scope.launch { store.cancelSetup() } },
            )
        is SecurityStore.SetupPhase.JustEnabled ->
            BackupCodesSheet(
                codes = setup.backupCodes,
                title = "Two-Factor Authentication Enabled",
                onDone = { store.finishSetup() },
            )
        SecurityStore.SetupPhase.Idle -> {}
    }

    if (showDisable) {
        TotpDisableSheet(
            error = store.setupError,
            isBusy = store.isBusy,
            onDisable = { totp, backup ->
                scope.launch { if (store.disable(totp, backup)) showDisable = false }
            },
            onCancel = { showDisable = false },
        )
    }

    regeneratedCodes?.let { codes ->
        BackupCodesSheet(codes = codes, title = "New Backup Codes", onDone = { regeneratedCodes = null })
    }

    pendingRevoke?.let { session ->
        AlertDialog(
            onDismissRequest = { pendingRevoke = null },
            title = { Text("Sign this device out?") },
            confirmButton = {
                TextButton(onClick = {
                    pendingRevoke = null
                    scope.launch { store.revoke(session) }
                }) { Text("Sign Out Device") }
            },
            dismissButton = { TextButton(onClick = { pendingRevoke = null }) { Text("Cancel") } },
        )
    }

    if (showRevokeAll) {
        AlertDialog(
            onDismissRequest = { showRevokeAll = false },
            title = { Text("Sign out every device, including this one?") },
            confirmButton = {
                TextButton(
                    onClick = {
                        showRevokeAll = false
                        scope.launch { store.revokeAll() }
                    },
                    modifier = Modifier.testTag("member_security_revoke_all_confirm"),
                ) { Text("Sign Out All Devices") }
            },
            dismissButton = { TextButton(onClick = { showRevokeAll = false }) { Text("Cancel") } },
        )
    }
}

@Composable
private fun SecurityList(
    overview: SecurityOverview,
    showManageWeb: Boolean,
    onEnable: () -> Unit,
    onDisable: () -> Unit,
    onRegenerate: () -> Unit,
    onRevoke: (AppSessionRow) -> Unit,
    onRevokeAll: () -> Unit,
    onManageWeb: () -> Unit,
) {
    LazyColumn(Modifier.fillMaxSize().testTag("member_security_list")) {
        // TOTP
        item { SecuritySectionHeader("Two-Factor Authentication") }
        if (overview.totpEnabled) {
            item { LabeledSecurityRow("Status", "Enabled") }
            item { LabeledSecurityRow("Backup Codes Remaining", overview.backupCodesRemaining.toString()) }
            item { ActionRow("Regenerate Backup Codes", "member_security_regenerate_codes", onRegenerate) }
            item { ActionRow("Disable Two-Factor Authentication", "member_security_disable_totp", onDisable, destructive = true) }
        } else {
            item { LabeledSecurityRow("Status", "Not enabled") }
            item { ActionRow("Enable Two-Factor Authentication", "member_security_enable_totp", onEnable) }
        }

        // App sessions
        item { HorizontalDivider() }
        item { SecuritySectionHeader("App Sessions") }
        overview.appSessions.forEach { session ->
            item { AppSessionRowView(session, onRevoke) }
        }
        if (overview.appSessions.size > 1) {
            item { ActionRow("Sign Out All Devices", "member_security_revoke_all", onRevokeAll, destructive = true) }
        }

        // Passkeys + Vault (web-managed)
        item { HorizontalDivider() }
        item { SecuritySectionHeader("Passkeys & Vault") }
        item { LabeledSecurityRow("Passkeys", overview.passkeyCount.toString()) }
        item { LabeledSecurityRow("Sealed Vault", if (overview.vaultActive) "Active" else "Not set up") }
        if (showManageWeb) {
            item { ManageWebRow(onManageWeb) }
        }
        item {
            Text(
                "Passkeys and the encryption vault are managed on the website.",
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                modifier = Modifier.padding(horizontal = 16.dp, vertical = 8.dp),
            )
        }
    }
}

/** Opens the web `/profile/security` page through the bridge — the in-app path
 *  to passkey and Sealed Vault management, which the WebView can host but native
 *  WebAuthn cannot. */
@Composable
private fun ManageWebRow(onClick: () -> Unit) {
    Row(
        Modifier.fillMaxWidth().clickable(onClick = onClick)
            .padding(horizontal = 16.dp, vertical = 14.dp).testTag("member_security_manage_web"),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text("Manage on the Website", style = MaterialTheme.typography.bodyLarge, modifier = Modifier.weight(1f))
        Icon(
            Icons.AutoMirrored.Filled.KeyboardArrowRight,
            contentDescription = null,
            tint = MaterialTheme.colorScheme.onSurfaceVariant,
        )
    }
}

@Composable
private fun SecuritySectionHeader(text: String) {
    Text(
        text,
        style = MaterialTheme.typography.labelMedium,
        color = MaterialTheme.colorScheme.primary,
        modifier = Modifier.padding(start = 16.dp, top = 12.dp, bottom = 4.dp),
    )
}

@Composable
private fun LabeledSecurityRow(label: String, value: String) {
    Row(
        Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text(label, style = MaterialTheme.typography.bodyLarge, modifier = Modifier.weight(1f))
        Text(value, style = MaterialTheme.typography.bodyLarge, color = MaterialTheme.colorScheme.onSurfaceVariant)
    }
}

@Composable
private fun ActionRow(label: String, tag: String, onClick: () -> Unit, destructive: Boolean = false) {
    Text(
        label,
        style = MaterialTheme.typography.bodyLarge,
        color = if (destructive) MaterialTheme.colorScheme.error else MaterialTheme.colorScheme.primary,
        modifier = Modifier.fillMaxWidth().clickable(onClick = onClick).padding(horizontal = 16.dp, vertical = 14.dp).testTag(tag),
    )
}

@Composable
private fun AppSessionRowView(session: AppSessionRow, onRevoke: (AppSessionRow) -> Unit) {
    Row(
        Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 10.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                Text(session.deviceLabel, style = MaterialTheme.typography.bodyMedium)
                if (session.isCurrent) {
                    Surface(shape = RoundedCornerShape(8.dp), color = MaterialTheme.colorScheme.primary.copy(alpha = 0.15f)) {
                        Text(
                            "This device",
                            style = MaterialTheme.typography.labelSmall,
                            modifier = Modifier.padding(horizontal = 6.dp, vertical = 1.dp),
                        )
                    }
                }
            }
            Text(
                sessionSubtitle(session),
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }
        TextButton(
            onClick = { onRevoke(session) },
            modifier = Modifier.testTag("member_security_revoke_${session.apiKeyId}"),
        ) { Text("Sign Out") }
    }
}

private fun sessionSubtitle(session: AppSessionRow): String {
    val created = "Created ${MemberDisplay.dateLabel(session.createdTime)}"
    val lastUsed = session.lastUsedTime ?: return created
    return "$created · Last used ${MemberDisplay.dateLabel(lastUsed)}"
}

// MARK: - Sheets

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun TotpSetupSheet(
    provisioningUri: String,
    error: String?,
    isBusy: Boolean,
    onConfirm: (String) -> Unit,
    onCancel: () -> Unit,
) {
    var code by remember { mutableStateOf("") }
    ModalBottomSheet(onDismissRequest = onCancel, modifier = Modifier.testTag("member_security_totp_sheet")) {
        Column(
            Modifier.fillMaxWidth().padding(horizontal = 24.dp).padding(bottom = 24.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.spacedBy(14.dp),
        ) {
            Text("Enable Two-Factor Authentication", style = MaterialTheme.typography.titleMedium)
            error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
            val qr = remember(provisioningUri) { qrBitmap(provisioningUri, 220) }
            if (qr != null) {
                Image(
                    bitmap = qr.asImageBitmap(),
                    contentDescription = "Two-factor QR code",
                    modifier = Modifier.size(220.dp).testTag("member_security_totp_qr"),
                )
            }
            Text(
                "Scan this code with your authenticator app, then enter the 6-digit code it shows.",
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
            OutlinedTextField(
                value = code,
                onValueChange = { code = it },
                label = { Text("6-digit code") },
                singleLine = true,
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.NumberPassword),
                modifier = Modifier.fillMaxWidth().testTag("member_security_totp_code"),
            )
            Button(
                onClick = { onConfirm(code) },
                enabled = !isBusy && code.isNotEmpty(),
                modifier = Modifier.fillMaxWidth().testTag("member_security_totp_confirm"),
            ) { Text("Confirm") }
            TextButton(onClick = onCancel, modifier = Modifier.fillMaxWidth()) { Text("Cancel") }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun TotpDisableSheet(
    error: String?,
    isBusy: Boolean,
    onDisable: (String, String) -> Unit,
    onCancel: () -> Unit,
) {
    var totpCode by remember { mutableStateOf("") }
    var backupCode by remember { mutableStateOf("") }
    ModalBottomSheet(onDismissRequest = onCancel, modifier = Modifier.testTag("member_security_disable_sheet")) {
        Column(
            Modifier.fillMaxWidth().padding(horizontal = 24.dp).padding(bottom = 24.dp),
            verticalArrangement = Arrangement.spacedBy(14.dp),
        ) {
            Text("Disable Two-Factor Authentication", style = MaterialTheme.typography.titleMedium)
            error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
            OutlinedTextField(
                value = totpCode,
                onValueChange = { totpCode = it },
                label = { Text("Current 6-digit code") },
                singleLine = true,
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.NumberPassword),
                modifier = Modifier.fillMaxWidth().testTag("member_security_disable_totp_code"),
            )
            OutlinedTextField(
                value = backupCode,
                onValueChange = { backupCode = it },
                label = { Text("Or an 8-character backup code") },
                singleLine = true,
                modifier = Modifier.fillMaxWidth().testTag("member_security_disable_backup_code"),
            )
            Button(
                onClick = { onDisable(totpCode, backupCode) },
                enabled = !isBusy && (totpCode.isNotEmpty() || backupCode.isNotEmpty()),
                modifier = Modifier.fillMaxWidth().testTag("member_security_disable_confirm"),
            ) { Text("Disable Two-Factor Authentication") }
            TextButton(onClick = onCancel, modifier = Modifier.fillMaxWidth()) { Text("Cancel") }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun BackupCodesSheet(codes: List<String>, title: String, onDone: () -> Unit) {
    ModalBottomSheet(onDismissRequest = onDone, modifier = Modifier.testTag("member_security_backup_codes")) {
        Column(
            Modifier.fillMaxWidth().padding(horizontal = 24.dp).padding(bottom = 24.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            Text(title, style = MaterialTheme.typography.titleMedium)
            Text(
                "Save these codes somewhere safe. Each one can be used once if you lose access to your authenticator app.",
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
            codes.forEach { code ->
                Text(code, fontFamily = FontFamily.Monospace, style = MaterialTheme.typography.bodyLarge)
            }
            Button(
                onClick = onDone,
                modifier = Modifier.fillMaxWidth().testTag("member_security_backup_codes_done"),
            ) { Text("Done") }
        }
    }
}

/** Renders an `otpauth://` provisioning URI to a QR bitmap natively (ZXing —
 *  the server sends the URI, not an image). */
private fun qrBitmap(content: String, sizePx: Int): Bitmap? {
    return try {
        val hints = mapOf(EncodeHintType.ERROR_CORRECTION to ErrorCorrectionLevel.M)
        val matrix = QRCodeWriter().encode(content, BarcodeFormat.QR_CODE, sizePx, sizePx, hints)
        val bmp = Bitmap.createBitmap(sizePx, sizePx, Bitmap.Config.ARGB_8888)
        for (x in 0 until sizePx) {
            for (y in 0 until sizePx) {
                bmp.setPixel(x, y, if (matrix[x, y]) android.graphics.Color.BLACK else android.graphics.Color.WHITE)
            }
        }
        bmp
    } catch (e: Exception) {
        null
    }
}
