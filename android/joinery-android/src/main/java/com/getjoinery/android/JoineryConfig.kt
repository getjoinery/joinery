package com.getjoinery.android

import androidx.compose.ui.graphics.Color

/**
 * Per-app configuration injected by the brand module. joinery-android itself
 * carries no brand knowledge — a second app consumes the library unchanged by
 * supplying a different config.
 */
data class JoineryConfig(
    /** Deployment origin, e.g. `https://dev.getjoinery.com`. No trailing slash. */
    val baseUrl: String,
    /** The `client_app` identifier sent on every request and used by the server
     *  for version minimums and tab pinning (e.g. `joinery-member-android`). */
    val clientApp: String,
    /** The app's marketing version, sent as `client-version`. */
    val clientVersion: String,
    /** Display name shown on the login screen and settings header. */
    val appName: String,
    /** Play Store page for the blocking upgrade screen. Optional during
     *  development (the screen still renders, without a store button). */
    val playStoreUrl: String? = null,
    /** In-app registration. Off by default — enabling it triggers Google Play's
     *  in-app account-deletion requirement, so an app turns it on only in a
     *  release that also ships deletion. */
    val registrationEnabled: Boolean = false,
    /** Brand accent color. */
    val accentColor: Color = Color(0xFF2A6BBF),
)
