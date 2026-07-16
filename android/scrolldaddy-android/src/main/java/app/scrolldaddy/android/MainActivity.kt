package app.scrolldaddy.android

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.ui.graphics.Color
import com.getjoinery.android.EncryptedCredentialStore
import com.getjoinery.android.JoineryAppRoot
import com.getjoinery.android.JoineryConfig
import com.getjoinery.dnsfilter.DnsFilterConfig
import com.getjoinery.billing.JoineryBilling
import com.getjoinery.dnsfilter.JoineryDnsFilter

/**
 * The ScrollDaddy app: pure brand shell. All behavior lives in joinery-android
 * and joinery-android-dnsfilter; this activity supplies configuration, registers
 * the DNS-filtering native screens, and mounts the root.
 */
class MainActivity : ComponentActivity() {

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        // Deterministic instrumented-test startup: wipe stored credentials so a
        // run can begin signed out.
        if (intent?.getBooleanExtra(EXTRA_RESET_AUTH, false) == true) {
            EncryptedCredentialStore(this, STORE_FILE).deleteCredentials()
        }

        val base = intent?.getStringExtra(EXTRA_BASE_URL) ?: "https://dev.getjoinery.com"

        // The DNS-filtering native screens the server's nav entries flip to by
        // name (dns_protection / dns_devices). Skippable so the instrumented gate
        // can prove the version-safe web fallback: with it off the flipped
        // entries land on their /profile/dns_filtering/… web URLs, exactly as an
        // older build would. Unregister (not merely skip) makes the flag
        // authoritative even if an earlier test in the process registered them.
        val dnsConfig = DnsFilterConfig(
            baseUrl = base,
            brandName = "ScrollDaddy",
            // Strict mode's all-traffic tunnel + SNI enforcement isn't shipped in
            // this build — the protection control offers Standard only (guardrail 3).
            strictModeAvailable = false,
        )
        if (intent?.getBooleanExtra(EXTRA_DISABLE_DNS, false) == true) {
            JoineryDnsFilter.unregisterScreens()
        } else {
            JoineryDnsFilter.registerScreens(dnsConfig)
        }

        // The billing module registers the `billing` native screen (Play
        // Billing purchase/restore, server-authoritative status). It stays
        // dormant until the server flips a nav entry to nativeScreen
        // "billing"; the web pricing page is the fallback.
        JoineryBilling.registerScreens()

        setContent {
            JoineryAppRoot(config = buildConfig(base), storeFileName = STORE_FILE)
        }
    }

    private fun buildConfig(base: String): JoineryConfig {
        val version = intent?.getStringExtra(EXTRA_CLIENT_VERSION)
            ?: packageManager.getPackageInfo(packageName, 0).versionName
            ?: "0.1.0"
        return JoineryConfig(
            baseUrl = base,
            clientApp = "scrolldaddy-android",
            clientVersion = version,
            appName = "ScrollDaddy",
            playStoreUrl = null,
            registrationEnabled = false,
            accentColor = Color(0xFF5C3DCC),
        )
    }

    private companion object {
        const val STORE_FILE = "app.scrolldaddy.android.session"
        const val EXTRA_RESET_AUTH = "reset_auth"
        const val EXTRA_BASE_URL = "base_url"
        const val EXTRA_CLIENT_VERSION = "client_version"
        const val EXTRA_DISABLE_DNS = "disable_dns_module"
    }
}
