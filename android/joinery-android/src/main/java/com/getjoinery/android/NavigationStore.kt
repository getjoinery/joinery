package com.getjoinery.android

import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue

/**
 * Fetches and holds the navigation table for the signed-in user. Owned by the
 * navigation shell; refreshed on foreground so server-side menu changes (and
 * revoked sessions — the fetch 401s) surface without a relaunch.
 */
class NavigationStore(private val client: ApiClient) {
    sealed class Phase {
        object Loading : Phase()
        data class Loaded(val navigation: AppNavigation) : Phase()
        data class Failed(val message: String) : Phase()
    }

    var phase by mutableStateOf<Phase>(Phase.Loading)
        private set

    /** Initial fetch: flips to Loading first so the shell shows a spinner. */
    suspend fun load() {
        phase = Phase.Loading
        fetch(keepCurrentOnFailure = false)
    }

    /** Background refresh: keeps the current table on screen; only a successful
     *  fetch replaces it. */
    suspend fun refresh() {
        fetch(keepCurrentOnFailure = true)
    }

    private suspend fun fetch(keepCurrentOnFailure: Boolean) {
        try {
            val envelope = client.request("GET", "/api/v1/app/navigation")
            val navigation = AppNavigation.from(envelope["data"])
            when {
                navigation != null -> phase = Phase.Loaded(navigation)
                !keepCurrentOnFailure -> phase = Phase.Failed("Navigation could not be loaded.")
            }
        } catch (e: JoineryApiError) {
            // Authentication/upgrade flip the app state via the client handlers;
            // the shell unmounts, so the phase here is moot.
            if (!keepCurrentOnFailure) phase = Phase.Failed(e.displayMessage)
        } catch (e: Exception) {
            if (!keepCurrentOnFailure) phase = Phase.Failed("Navigation could not be loaded.")
        }
    }
}
