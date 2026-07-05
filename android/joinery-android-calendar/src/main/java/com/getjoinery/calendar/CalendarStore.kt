package com.getjoinery.calendar

import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import com.getjoinery.android.JoineryApiError
import java.text.SimpleDateFormat
import java.util.Calendar
import java.util.Date
import java.util.Locale

/**
 * State for the calendar screen: one month window of aggregated items,
 * grouped by local day, plus the selected day. All writes go through
 * [CalendarApi] and reload the window — the server (shared with the web
 * calendar) is the single source of truth.
 */
class CalendarStore(
    val api: CalendarApi,
    val calendar: Calendar = Calendar.getInstance(),
    today: Date = Date(),
) {
    sealed class Phase {
        object Loading : Phase()
        object Loaded : Phase()
        data class Failed(val message: String) : Phase()
    }

    var phase by mutableStateOf<Phase>(Phase.Loading)
        private set
    var itemsByDay by mutableStateOf<Map<String, List<CalItem>>>(emptyMap())
        private set
    var displayedMonth by mutableStateOf(CalMonthMath.monthStart(today, calendar))
        private set
    var selectedDate by mutableStateOf(CalDisplay.startOfDay(today, calendar))

    /** Ignores stale in-flight loads after the month changes. */
    private var loadGeneration = 0

    val monthTitle: String
        get() = SimpleDateFormat("MMMM yyyy", Locale.getDefault())
            .apply { timeZone = calendar.timeZone }
            .format(displayedMonth)

    fun items(day: Date): List<CalItem> =
        (itemsByDay[CalDisplay.dayKey(day, calendar)] ?: emptyList())
            .sortedWith(CalDisplay.agendaComparator)

    /** Up to [limit] dot colors for a day cell. */
    fun dotColors(day: Date, limit: Int = 3): List<String> =
        items(day).take(limit).map { it.colorHex }

    suspend fun initialLoad() {
        phase = Phase.Loading
        loadWindow()
    }

    /** Re-fetch the current month window (pull-to-refresh, after saves). */
    suspend fun reload() {
        loadWindow()
    }

    suspend fun show(month: Date) {
        val anchor = CalMonthMath.monthStart(month, calendar)
        if (anchor == displayedMonth) return
        displayedMonth = anchor
        // Keep a selection inside the visible month.
        if (CalDisplay.dayKey(selectedDate, calendar).substring(0, 7) !=
            CalDisplay.dayKey(anchor, calendar).substring(0, 7)
        ) {
            selectedDate = anchor
        }
        loadWindow()
    }

    suspend fun shiftMonth(delta: Int) {
        val c = calendar.clone() as Calendar
        c.time = displayedMonth
        c.add(Calendar.MONTH, delta)
        show(c.time)
    }

    suspend fun goToToday() {
        val today = CalDisplay.startOfDay(Date(), calendar)
        selectedDate = today
        show(today)
        if (phase is Phase.Loaded) return
        loadWindow()
    }

    private suspend fun loadWindow() {
        loadGeneration += 1
        val generation = loadGeneration
        val (start, end) = CalMonthMath.fetchWindow(displayedMonth, calendar)
        try {
            val feed = api.feed(start, end)
            if (generation != loadGeneration) return
            val grouped = HashMap<String, MutableList<CalItem>>()
            for (item in feed.items) {
                for (key in CalDisplay.dayKeys(item, calendar)) {
                    grouped.getOrPut(key) { ArrayList() }.add(item)
                }
            }
            itemsByDay = grouped
            phase = Phase.Loaded
        } catch (e: Exception) {
            if (generation != loadGeneration) return
            if (phase is Phase.Loaded) return
            phase = Phase.Failed(
                (e as? JoineryApiError)?.displayMessage ?: (e.message ?: "Something went wrong."),
            )
        }
    }
}
