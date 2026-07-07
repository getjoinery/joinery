package com.getjoinery.member

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.ui.graphics.Color
import com.getjoinery.android.EncryptedCredentialStore
import com.getjoinery.android.JoineryAppRoot
import com.getjoinery.android.JoineryConfig
import com.getjoinery.aichat.JoineryAIChat
import com.getjoinery.calendar.JoineryCalendar
import com.getjoinery.mail.JoineryMail
import com.getjoinery.memberkit.JoineryMember

/**
 * The Joinery member app: pure brand shell. All behavior lives in
 * joinery-android; this activity supplies configuration and mounts the root.
 */
class MainActivity : ComponentActivity() {

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        // Deterministic instrumented-test startup: wipe stored credentials so a
        // run can begin signed out. Passed as an intent extra by the test harness.
        if (intent?.getBooleanExtra(EXTRA_RESET_AUTH, false) == true) {
            EncryptedCredentialStore(this, STORE_FILE).deleteCredentials()
        }

        // Layered native screens this build ships. The server's navigation
        // entries flip to them by name; unknown names fall back to the web.
        JoineryMail.registerScreens()
        JoineryCalendar.registerScreens()
        JoineryAIChat.registerScreens()
        // The member module registration is skippable so the instrumented gate
        // can prove the version-safe fallback: with it off, the flipped member
        // entries land on their web URLs, exactly as an older build would.
        // Unregistering (not merely skipping) makes the flag authoritative even
        // if an earlier test in the same process registered the screens — the
        // registry is a process-global map.
        if (intent?.getBooleanExtra(EXTRA_DISABLE_MEMBER, false) == true) {
            JoineryMember.unregisterScreens()
        } else {
            JoineryMember.registerScreens()
        }

        setContent {
            JoineryAppRoot(config = buildConfig(), storeFileName = STORE_FILE)
        }
    }

    private fun buildConfig(): JoineryConfig {
        // Instrumented tests may point the app at a different deployment or
        // masquerade as a different build number; production uses the baked-ins.
        val base = intent?.getStringExtra(EXTRA_BASE_URL) ?: "https://dev.getjoinery.com"
        val version = intent?.getStringExtra(EXTRA_CLIENT_VERSION)
            ?: packageManager.getPackageInfo(packageName, 0).versionName
            ?: "0.1.0"
        return JoineryConfig(
            baseUrl = base,
            clientApp = "joinery-member-android",
            clientVersion = version,
            appName = "Joinery",
            playStoreUrl = null,
            registrationEnabled = false,
            accentColor = Color(0xFF2A6BBF),
        )
    }

    private companion object {
        const val STORE_FILE = "com.getjoinery.member.session"
        const val EXTRA_RESET_AUTH = "reset_auth"
        const val EXTRA_BASE_URL = "base_url"
        const val EXTRA_CLIENT_VERSION = "client_version"
        const val EXTRA_DISABLE_MEMBER = "disable_member_module"
    }
}
