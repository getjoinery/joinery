package com.getjoinery.memberkit

import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import com.getjoinery.android.JoineryApiError

/** Shared phase for the member screens (loading / loaded / failed). */
sealed class MemberPhase {
    object Loading : MemberPhase()
    object Loaded : MemberPhase()
    data class Failed(val message: String) : MemberPhase()
}

internal fun displayMessage(e: Exception): String =
    (e as? JoineryApiError)?.displayMessage ?: (e.message ?: "Something went wrong.")

/**
 * State for the dashboard screen: one summary load, refreshed on pull, on
 * foreground, and on return from a child screen (subscription cancel, event
 * withdraw, mute etc. change the counts). The server is the single source of
 * truth, shared with the web dashboard. A [loadGeneration] guard drops a slow
 * older response so it never overwrites a newer one.
 */
class ProfileStore(val api: MemberApi) {
    var phase by mutableStateOf<MemberPhase>(MemberPhase.Loading)
        private set
    var summary by mutableStateOf<DashboardSummary?>(null)
        private set

    private var loadGeneration = 0

    suspend fun initialLoad() {
        phase = MemberPhase.Loading
        reload()
    }

    suspend fun reload() {
        loadGeneration += 1
        val generation = loadGeneration
        try {
            val loaded = api.dashboard()
            if (generation != loadGeneration) return
            summary = loaded
            phase = MemberPhase.Loaded
        } catch (e: Exception) {
            if (generation != loadGeneration) return
            if (phase is MemberPhase.Loaded) return
            phase = MemberPhase.Failed(displayMessage(e))
        }
    }
}

/**
 * State for the paginated order list. 10/page, matching the web page — the
 * server's `per_page` always wins. Reloads and paging are [loadGeneration]
 * guarded so a stale response never lands over a fresh one.
 */
class OrderListStore(val api: MemberApi) {
    var phase by mutableStateOf<MemberPhase>(MemberPhase.Loading)
        private set
    var orders by mutableStateOf<List<OrderSummary>>(emptyList())
        private set
    var isLoadingMore by mutableStateOf(false)
        private set

    private var totalCount = 0
    private var loadGeneration = 0
    val hasMore: Boolean get() = orders.size < totalCount

    suspend fun initialLoad() {
        phase = MemberPhase.Loading
        reload()
    }

    suspend fun reload() {
        loadGeneration += 1
        val generation = loadGeneration
        try {
            val page = api.orders(0)
            if (generation != loadGeneration) return
            orders = page.orders
            totalCount = page.totalCount
            phase = MemberPhase.Loaded
        } catch (e: Exception) {
            if (generation != loadGeneration) return
            if (phase is MemberPhase.Loaded) return
            phase = MemberPhase.Failed(displayMessage(e))
        }
    }

    suspend fun loadMore() {
        if (!hasMore || isLoadingMore) return
        isLoadingMore = true
        val generation = loadGeneration
        try {
            val page = api.orders(orders.size)
            if (generation != loadGeneration) return
            val known = orders.map { it.orderId }.toHashSet()
            orders = orders + page.orders.filter { !known.contains(it.orderId) }
            totalCount = page.totalCount
        } catch (e: Exception) {
            // Paging failures are silent; the next scroll retries.
        } finally {
            isLoadingMore = false
        }
    }
}

/**
 * State for the subscriptions screen: active + cancelled lists, current tier,
 * and payment source. Cancel goes through `orders_recurring_action` and
 * reloads — the server is the single source of truth. [loadGeneration] guards
 * the reloads.
 */
class SubscriptionStore(val api: MemberApi) {
    var phase by mutableStateOf<MemberPhase>(MemberPhase.Loading)
        private set
    var payload by mutableStateOf<SubscriptionSummaryPayload?>(null)
        private set
    var cancelError by mutableStateOf<String?>(null)
        private set

    private var loadGeneration = 0

    suspend fun initialLoad() {
        phase = MemberPhase.Loading
        reload()
    }

    suspend fun reload() {
        loadGeneration += 1
        val generation = loadGeneration
        try {
            val loaded = api.subscriptions()
            if (generation != loadGeneration) return
            payload = loaded
            phase = MemberPhase.Loaded
        } catch (e: Exception) {
            if (generation != loadGeneration) return
            if (phase is MemberPhase.Loaded) return
            phase = MemberPhase.Failed(displayMessage(e))
        }
    }

    suspend fun cancel(orderItemId: Int) {
        cancelError = null
        try {
            api.cancelSubscription(orderItemId)
            reload()
        } catch (e: Exception) {
            cancelError = (e as? JoineryApiError)?.displayMessage ?: "Could not cancel the subscription."
        }
    }

    fun clearCancelError() {
        cancelError = null
    }
}

/**
 * State for the status-tabbed event list. 10/page, matching the web page.
 * Withdraw goes through `event_withdraw` (with a client confirmation dialog,
 * matching the web flow) and reloads. [loadGeneration] guards reloads and
 * paging so a fast filter tap can't strand an older status's response under
 * the checked filter.
 */
class EventListStore(val api: MemberApi) {
    var phase by mutableStateOf<MemberPhase>(MemberPhase.Loading)
        private set
    var registrations by mutableStateOf<List<EventRegistration>>(emptyList())
        private set
    var isLoadingMore by mutableStateOf(false)
        private set
    var status by mutableStateOf(EventStatusFilter.ALL)
        private set
    var withdrawError by mutableStateOf<String?>(null)
        private set

    private var totalCount = 0
    private var loadGeneration = 0
    val hasMore: Boolean get() = registrations.size < totalCount

    suspend fun initialLoad() {
        phase = MemberPhase.Loading
        reload()
    }

    suspend fun reload() {
        loadGeneration += 1
        val generation = loadGeneration
        val forStatus = status
        try {
            val page = api.events(forStatus, 0)
            if (generation != loadGeneration) return
            registrations = page.registrations
            totalCount = page.totalCount
            phase = MemberPhase.Loaded
        } catch (e: Exception) {
            if (generation != loadGeneration) return
            if (phase is MemberPhase.Loaded) return
            phase = MemberPhase.Failed(displayMessage(e))
        }
    }

    suspend fun loadMore() {
        if (!hasMore || isLoadingMore) return
        isLoadingMore = true
        val generation = loadGeneration
        try {
            val page = api.events(status, registrations.size)
            if (generation != loadGeneration) return
            val known = registrations.map { it.registrantId }.toHashSet()
            registrations = registrations + page.registrations.filter { !known.contains(it.registrantId) }
            totalCount = page.totalCount
        } catch (e: Exception) {
            // Paging failures are silent; the next scroll retries.
        } finally {
            isLoadingMore = false
        }
    }

    suspend fun select(newStatus: EventStatusFilter) {
        if (newStatus == status) return
        status = newStatus
        initialLoad()
    }

    suspend fun withdraw(registrantId: Int) {
        withdrawError = null
        try {
            api.withdraw(registrantId)
            reload()
        } catch (e: Exception) {
            withdrawError = (e as? JoineryApiError)?.displayMessage ?: "Could not withdraw from the event."
        }
    }

    fun clearWithdrawError() {
        withdrawError = null
    }
}
