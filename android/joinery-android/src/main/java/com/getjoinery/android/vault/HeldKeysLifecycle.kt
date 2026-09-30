package com.getjoinery.android.vault

import android.os.Handler
import android.os.Looper
import androidx.lifecycle.DefaultLifecycleObserver
import androidx.lifecycle.LifecycleOwner
import androidx.lifecycle.ProcessLifecycleOwner

/**
 * Ties [HeldKeys] to the app's foreground: leaving starts the lock clock, a
 * timer drops the keys once it runs out while still away, and returning after
 * the limit finds them already gone (specs/fortress_mobile_apps.md § R3).
 */
internal object HeldKeysLifecycle {
    private var installed = false
    private val main = Handler(Looper.getMainLooper())

    fun install(held: HeldKeys) {
        main.post {
            if (installed) return@post
            installed = true
            val timer = Runnable { held.backgroundTimerFired() }
            ProcessLifecycleOwner.get().lifecycle.addObserver(object : DefaultLifecycleObserver {
                override fun onStop(owner: LifecycleOwner) {
                    held.onBackground()
                    main.removeCallbacks(timer)
                    main.postDelayed(timer, held.lockAfterMillis + 1_000)
                }

                override fun onStart(owner: LifecycleOwner) {
                    main.removeCallbacks(timer)
                    held.onForeground()
                }
            })
        }
    }
}
