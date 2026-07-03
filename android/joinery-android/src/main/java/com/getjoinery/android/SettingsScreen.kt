package com.getjoinery.android

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.launch

/**
 * The native settings surface: who you are (from `auth/session`), the
 * server-driven account forms, the App Sessions page (a webview destination),
 * and sign out.
 *
 * Reached from the navigation shell's More tab. [onExit] returns to the More
 * list (null when standalone); [web] supplies the App Sessions webview.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun SettingsScreen(
    session: SessionController,
    user: UserSummary,
    web: WebSessionCoordinator? = null,
    onExit: (() -> Unit)? = null,
) {
    var route by remember { mutableStateOf<SettingsRoute>(SettingsRoute.Root) }
    val scope = rememberCoroutineScope()

    // System back: a sub-form returns to the Settings root; the root leaves for
    // the More list.
    BackHandler(enabled = route != SettingsRoute.Root || onExit != null) {
        if (route != SettingsRoute.Root) route = SettingsRoute.Root else onExit?.invoke()
    }

    val title = when (route) {
        SettingsRoute.Root -> "Settings"
        SettingsRoute.AccountEdit -> "Edit Account"
        SettingsRoute.ContactPreferences -> "Contact Preferences"
        SettingsRoute.PasswordEdit -> "Change Password"
        SettingsRoute.AppSessions -> "App Sessions"
    }

    Scaffold(topBar = {
        TopAppBar(
            title = { Text(title) },
            navigationIcon = {
                when {
                    route != SettingsRoute.Root ->
                        IconButton(onClick = { route = SettingsRoute.Root }) {
                            Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "Back")
                        }
                    onExit != null ->
                        IconButton(onClick = onExit) {
                            Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "Back")
                        }
                }
            },
        )
    }) { padding ->
        val content = Modifier.fillMaxSize().padding(padding)
        when (route) {
            SettingsRoute.Root -> SettingsRoot(session, user, web != null, content) { route = it }
            SettingsRoute.AccountEdit ->
                FormScreen(session.client, "account_edit", content) {
                    scope.launch { session.refreshUser() }
                }
            SettingsRoute.ContactPreferences ->
                FormScreen(session.client, "contact_preferences", content)
            SettingsRoute.PasswordEdit ->
                FormScreen(session.client, "password_edit", content)
            SettingsRoute.AppSessions ->
                if (web != null) {
                    val state = remember { WebPageState() }
                    WebScreen("/profile/security", session.client, web, state, content)
                }
        }
    }
}

@Composable
private fun SettingsRoot(
    session: SessionController,
    user: UserSummary,
    showAppSessions: Boolean,
    modifier: Modifier,
    navigate: (SettingsRoute) -> Unit,
) {
    var confirmSignOut by remember { mutableStateOf(false) }
    var signingOut by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()

    Column(modifier.verticalScroll(rememberScrollState())) {
        Column(Modifier.padding(16.dp)) {
            Text(
                user.displayName.ifEmpty { user.email },
                style = MaterialTheme.typography.titleMedium,
                fontWeight = FontWeight.Bold,
                modifier = Modifier.testTag("settings_display_name"),
            )
            Text(
                user.email,
                style = MaterialTheme.typography.bodyMedium,
                color = MaterialTheme.colorScheme.outline,
                modifier = Modifier.testTag("settings_email"),
            )
        }
        HorizontalDivider()
        SectionLabel("Subscription")
        Text(
            "Plan: ${user.tier?.name ?: "Free"}",
            modifier = Modifier.padding(horizontal = 16.dp, vertical = 12.dp).testTag("settings_tier"),
        )
        HorizontalDivider()
        SectionLabel("Account")
        SettingsRow("Edit Account", "settings_account_edit") { navigate(SettingsRoute.AccountEdit) }
        SettingsRow("Contact Preferences", "settings_contact_preferences") { navigate(SettingsRoute.ContactPreferences) }
        SettingsRow("Change Password", "settings_password_edit") { navigate(SettingsRoute.PasswordEdit) }
        if (showAppSessions) {
            HorizontalDivider()
            SectionLabel("Security")
            SettingsRow("App Sessions", "settings_app_sessions") { navigate(SettingsRoute.AppSessions) }
        }
        HorizontalDivider()
        Text(
            if (signingOut) "Signing out…" else "Sign Out",
            color = MaterialTheme.colorScheme.error,
            modifier = Modifier
                .fillMaxWidth()
                .clickable(enabled = !signingOut) { confirmSignOut = true }
                .padding(16.dp)
                .testTag("settings_sign_out"),
        )
    }

    if (confirmSignOut) {
        AlertDialog(
            onDismissRequest = { confirmSignOut = false },
            title = { Text("Sign out of this device?") },
            confirmButton = {
                TextButton(
                    onClick = {
                        confirmSignOut = false
                        signingOut = true
                        scope.launch { session.logout() }
                    },
                    modifier = Modifier.testTag("settings_sign_out_confirm"),
                ) { Text("Sign Out") }
            },
            dismissButton = {
                TextButton(onClick = { confirmSignOut = false }) { Text("Cancel") }
            },
        )
    }
}

@Composable
private fun SectionLabel(text: String) {
    Text(
        text,
        style = MaterialTheme.typography.labelMedium,
        color = MaterialTheme.colorScheme.primary,
        modifier = Modifier.padding(start = 16.dp, top = 12.dp, bottom = 4.dp),
    )
}

@Composable
private fun SettingsRow(label: String, tag: String, onClick: () -> Unit) {
    Text(
        label,
        modifier = Modifier
            .fillMaxWidth()
            .clickable(onClick = onClick)
            .padding(16.dp)
            .testTag(tag),
    )
}

private enum class SettingsRoute { Root, AccountEdit, ContactPreferences, PasswordEdit, AppSessions }
