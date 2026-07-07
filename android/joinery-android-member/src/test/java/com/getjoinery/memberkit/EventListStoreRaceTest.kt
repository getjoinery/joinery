package com.getjoinery.memberkit

import com.getjoinery.android.ApiClient
import com.getjoinery.android.JoineryConfig
import kotlinx.coroutines.CompletableDeferred
import kotlinx.coroutines.launch
import kotlinx.coroutines.test.UnconfinedTestDispatcher
import kotlinx.coroutines.test.runTest
import org.junit.Assert.assertEquals
import org.junit.Test

/**
 * The `loadGeneration` stale-load guard: a slower response for an older status
 * filter must not overwrite the newer one. Reproduces the reported race — tap
 * ACTIVE (slow), then EXPIRED (fast), then let ACTIVE resolve — and asserts the
 * rendered list belongs to the second selection.
 */
class EventListStoreRaceTest {

    /** A MemberApi whose ACTIVE response is gated so the test can order it after
     *  the EXPIRED response. Each status returns a single, identifiable row. */
    private class RaceApi : MemberApi(ApiClient(JoineryConfig("http://127.0.0.1", "test", "0", "test"))) {
        val activeGate = CompletableDeferred<Unit>()

        override suspend fun events(status: EventStatusFilter, offset: Int): EventPage {
            if (status == EventStatusFilter.ACTIVE) activeGate.await()
            return EventPage(
                registrations = listOf(
                    EventRegistration(
                        registrantId = status.ordinal + 1,
                        eventId = 0,
                        eventName = status.slug,
                        sessionDisplayType = 0,
                        nextSessionTime = null,
                        status = status.slug,
                        expiresTime = null,
                        webUrl = "",
                    ),
                ),
                totalCount = 1,
                offset = 0,
                perPage = 10,
                statusFilter = status.slug,
            )
        }
    }

    @Test
    fun slowOlderSelectionDoesNotOverwriteNewer() = runTest {
        val api = RaceApi()
        val store = EventListStore(api)

        // Unconfined so the launched selection runs eagerly up to its first real
        // suspension (the gate), guaranteeing ACTIVE is the in-flight generation
        // before EXPIRED starts.
        val first = launch(UnconfinedTestDispatcher(testScheduler)) {
            store.select(EventStatusFilter.ACTIVE)
        }
        // Second selection (EXPIRED) starts and completes immediately.
        store.select(EventStatusFilter.EXPIRED)
        // Now release the stale ACTIVE response — the guard must drop it.
        api.activeGate.complete(Unit)
        first.join()

        assertEquals(EventStatusFilter.EXPIRED, store.status)
        assertEquals(1, store.registrations.size)
        assertEquals(EventStatusFilter.EXPIRED.slug, store.registrations.first().eventName)
    }
}
