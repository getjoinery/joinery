package com.getjoinery.member

import androidx.compose.ui.test.junit4.createEmptyComposeRule
import androidx.compose.ui.test.onNodeWithTag
import androidx.compose.ui.test.performClick
import androidx.test.ext.junit.runners.AndroidJUnit4
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith

/**
 * Revoke All from the native security screen signs the app out — the revoke
 * kills the current session key, the reload on the dead key 401s, and the core
 * sign-out handler returns the app to the login screen. Runs LAST: it revokes
 * every session key for the fixture user (the gate sets
 * `app_bridge_key_check_seconds=0` for this leg).
 */
@RunWith(AndroidJUnit4::class)
class RevocationGateTest {

    @get:Rule
    val compose = createEmptyComposeRule()

    @Test
    fun revokeAllSignsAppOut() {
        MemberGate.launch()
        compose.signIn()

        compose.openMoreEntry("core-profile")
        compose.awaitTag("member_profile_dashboard")
        compose.scrollListTo("member_profile_dashboard", "member_profile_tile_security")
        compose.onNodeWithTag("member_profile_tile_security").performClick()

        compose.awaitTag("member_security_list")
        // The revoke-all control sits below the accumulated session rows — scroll
        // to it (lazy lists don't expose off-screen nodes).
        compose.scrollListTo("member_security_list", "member_security_revoke_all")
        compose.onNodeWithTag("member_security_revoke_all").performClick()
        // Confirm the destructive dialog.
        compose.onNodeWithTag("member_security_revoke_all_confirm").performClick()

        // The app returns to the login screen once the dead key 401s.
        compose.awaitTag("login_submit")
    }
}
