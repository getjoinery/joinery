package com.getjoinery.billing

import com.getjoinery.android.NativeScreenRegistry

/**
 * joinery-android-billing — the native in-app purchase surface for any
 * Joinery deployment that sells subscriptions inside its Android app. The
 * app registers the screen at launch and the server's navigation routing
 * table lights it up (nativeScreen "billing" on a menu entry), with the web
 * pricing page as the version-skew fallback.
 *
 * Screen names (matched against amu_native_screen):
 *   `billing` → BillingScreen (plans, purchase, restore, manage-routing)
 */
object JoineryBilling {
    private val SCREENS = listOf("billing")

    /** Register the native billing screen. */
    fun registerScreens() {
        NativeScreenRegistry.register("billing") { ctx ->
            BillingScreen(ctx.session.client, onBack = ctx.onExit)
        }
    }

    /** Remove the screens (instrumented web-fallback tests). */
    fun unregisterScreens() {
        SCREENS.forEach { NativeScreenRegistry.unregister(it) }
    }
}
