package app.scrolldaddy.android

import androidx.compose.ui.test.junit4.createEmptyComposeRule
import androidx.compose.ui.test.onNodeWithTag
import androidx.compose.ui.test.performClick
import androidx.test.ext.junit.runners.AndroidJUnit4
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith

/**
 * Phase 1 gate — the branded shell on joinery-android. Logging in with a
 * website-created account lands on the ScrollDaddy-branded navigation shell,
 * and the DNS-filtering surface is reachable: the native ProtectionScreen when
 * this build knows the screen name (dns_protection registered), the
 * /profile/dns_filtering/… webview otherwise.
 *
 * Requires the runner creds (ScrollDaddyGate) for a dev account with DNS
 * filtering, and the server to carry `scrolldaddy-android` entries in
 * `app_navigation` (tab pinning) and `nativeScreen` on the dns_filtering
 * profileMenu entries.
 */
@RunWith(AndroidJUnit4::class)
class ShellUITests {

    @get:Rule
    val compose = createEmptyComposeRule()

    @Test
    fun loginLandsOnBrandedShell() {
        ScrollDaddyGate.launch()
        compose.signIn()
        // The Filtering tab (dns-filtering, pinned for scrolldaddy-android via
        // app_navigation) is present in the shell.
        assertTrue(
            "the DNS-filtering surface was not pinned into the shell",
            compose.hasTag("nav_tab_dns-filtering"),
        )
    }

    @Test
    fun filteringSurfaceReachableNatively() {
        ScrollDaddyGate.launch()
        compose.signIn()

        // The server's nativeScreen makes the dns-filtering entry a native
        // destination this build knows (dns_protection → ProtectionScreen), so
        // opening it renders natively.
        compose.openNavEntry("dns-filtering")
        compose.awaitTag("protection_list")
        compose.onNodeWithTag("protection_list")
        assertTrue(
            "the Filtering surface did not render the native protection screen",
            compose.hasTag("protection_list") || compose.hasTag("protection_status_label"),
        )
        // A native screen carries no webview.
        assertTrue("unexpected webview on the native protection screen", !compose.hasTag("web_view"))
    }

    @Test
    fun filteringFallsBackToWebviewWithoutModule() {
        // A build that doesn't register the DNS screens (older/version-skew)
        // lands the flipped entry on its web fallback — proving the server's
        // nativeScreen routing degrades safely.
        ScrollDaddyGate.launch(disableDns = true)
        compose.signIn()

        compose.openNavEntry("dns-filtering")
        compose.awaitTag("web_view")
        assertTrue("web fallback did not load", compose.hasTag("web_view"))
        assertTrue("native screen rendered despite disabled module", !compose.hasTag("protection_list"))
    }
}
