package com.getjoinery.member

import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.junit4.createEmptyComposeRule
import androidx.compose.ui.test.onNodeWithTag
import androidx.compose.ui.test.performClick
import androidx.test.espresso.Espresso
import androidx.test.ext.junit.runners.AndroidJUnit4
import org.junit.Assert.assertFalse
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith

/**
 * The core member-gate legs: every native member screen renders (no webview
 * present), the deliberately-web surfaces load through the bridge from their
 * native entry points, and a build without the member module lands the flipped
 * entries on their web fallback.
 */
@RunWith(AndroidJUnit4::class)
class MemberScreensTest {

    @get:Rule
    val compose = createEmptyComposeRule()

    @Test
    fun nativeMemberScreensRenderWithoutWebview() {
        MemberGate.launch()
        compose.signIn()

        // Dashboard (native, from the menu entry).
        compose.openNavEntry("core-profile")
        compose.awaitTag("member_profile_dashboard")
        compose.onNodeWithTag("member_profile_dashboard").assertIsDisplayed()
        // A native screen carries no webview; a web fallback would show none of
        // the member_* tags and instead a web_view node.
        assertFalse("webview present on native dashboard", compose.hasTag("web_view"))

        // Security + Conversations are reached from the dashboard tiles.
        compose.scrollListTo("member_profile_dashboard", "member_profile_tile_security")
        compose.onNodeWithTag("member_profile_tile_security").performClick()
        compose.awaitTag("member_security_list")
        compose.onNodeWithTag("member_security_list").assertIsDisplayed()
        Espresso.pressBack()

        compose.awaitTag("member_profile_dashboard")
        compose.scrollListTo("member_profile_dashboard", "member_profile_tile_conversations")
        compose.onNodeWithTag("member_profile_tile_conversations").performClick()
        compose.awaitTag("member_conversations_list")
        compose.onNodeWithTag("member_conversations_list").assertIsDisplayed()
        Espresso.pressBack()
        // No back press out of the dashboard: when profile is a pinned tab it
        // is the tab root, and openNavEntry switches tabs itself.

        // Orders / Subscriptions / Events reachable as menu entries directly.
        compose.openNavEntry("core-orders")
        compose.awaitTag("member_orders_list")
        compose.onNodeWithTag("member_orders_list").assertIsDisplayed()
        Espresso.pressBack()

        compose.openNavEntry("core-subscriptions")
        compose.awaitTag("member_subscriptions_list")
        compose.onNodeWithTag("member_subscriptions_list").assertIsDisplayed()
        Espresso.pressBack()

        compose.openNavEntry("core-events")
        compose.awaitTag("member_events_list")
        compose.onNodeWithTag("member_events_list").assertIsDisplayed()
    }

    @Test
    fun deliberatelyWebSurfacesLoadThroughBridge() {
        MemberGate.launch()
        compose.signIn()

        // Change Plan (from native subscriptions) is a web bridge surface.
        compose.openNavEntry("core-subscriptions")
        compose.awaitTag("member_subscriptions_change_plan")
        compose.onNodeWithTag("member_subscriptions_change_plan").performClick()
        // The authenticated webview shows its loading/loaded chrome, never a
        // native member list.
        compose.awaitTag("web_view")
        Espresso.pressBack()
        Espresso.pressBack()

        // Notifications (from the dashboard) is likewise a web bridge surface.
        compose.openNavEntry("core-profile")
        compose.awaitTag("member_profile_dashboard")
        compose.scrollListTo("member_profile_dashboard", "member_profile_notifications")
        compose.onNodeWithTag("member_profile_notifications").performClick()
        compose.awaitTag("web_view")
    }

    @Test
    fun securityManageWebRowOpensWebPage() {
        MemberGate.launch()
        compose.signIn()

        compose.openNavEntry("core-profile")
        compose.awaitTag("member_profile_dashboard")
        compose.scrollListTo("member_profile_dashboard", "member_profile_tile_security")
        compose.onNodeWithTag("member_profile_tile_security").performClick()

        compose.awaitTag("member_security_list")
        // Passkeys/vault are web-managed; the row must exist and open the bridge.
        compose.scrollListTo("member_security_list", "member_security_manage_web")
        compose.onNodeWithTag("member_security_manage_web").assertIsDisplayed()
        compose.onNodeWithTag("member_security_manage_web").performClick()
        compose.awaitTag("web_view")
    }

    @Test
    fun buildWithoutModuleFallsBackToWeb() {
        // With the member module registration skipped, the flipped `profile`
        // entry must land on its web fallback URL, not the native dashboard.
        MemberGate.launch(disableMember = true)
        compose.signIn()

        compose.openNavEntry("core-profile")
        compose.awaitTag("web_view")
        assertFalse("native dashboard rendered without the module",
            compose.hasTag("member_profile_dashboard"))
    }
}
