package com.getjoinery.calendar

import com.getjoinery.android.JsonValue
import java.text.DateFormat
import java.text.DateFormatSymbols
import java.text.SimpleDateFormat
import java.util.Calendar
import java.util.Date
import java.util.Locale
import java.util.TimeZone

/** One item on the personal calendar — the `calendar_feed` wire shape. Most
 *  items are projections (events, bookings); native personal entries also
 *  carry edit coordinates (entryId, occurrenceDate) so the editor can open
 *  without parsing web URLs. */
data class CalItem(
    val start: String,          // UTC "yyyy-MM-dd HH:mm:ss"
    val end: String,
    val allDay: Boolean,
    val title: String,
    val url: String?,
    val colorHex: String,
    val type: String,           // event | booking | external | personal
    val sourceKey: String,
    val blocksAvailability: Boolean,
    val entryId: Int?,
    val occurrenceDate: String?, // "yyyy-MM-dd", virtual occurrences only
) {
    /** Native entries are the ones this module can edit in place. */
    val isEditableEntry: Boolean get() = entryId != null
    val isRecurringOccurrence: Boolean get() = occurrenceDate != null

    companion object {
        fun from(json: JsonValue): CalItem? {
            val start = json["start"]?.stringValue ?: return null
            if (start.isEmpty()) return null
            return CalItem(
                start = start,
                end = json["end"]?.stringValue ?: start,
                allDay = json["all_day"]?.boolValue ?: false,
                title = json["title"]?.stringValue ?: "",
                url = json["url"]?.takeUnless { it.isNull }?.stringValue,
                colorHex = json["color"]?.stringValue ?: "#6b7280",
                type = json["type"]?.stringValue ?: "personal",
                sourceKey = json["source_key"]?.stringValue
                    ?: "$start-${json["title"]?.stringValue ?: ""}",
                blocksAvailability = json["blocks_availability"]?.boolValue ?: true,
                entryId = json["entry_id"]?.takeUnless { it.isNull }?.intValue,
                occurrenceDate = json["occurrence_date"]?.takeUnless { it.isNull }?.stringValue,
            )
        }
    }
}

/** The `calendar_feed` payload. */
data class CalendarFeed(
    val items: List<CalItem>,
    val timezone: String,
) {
    companion object {
        fun from(data: JsonValue?): CalendarFeed? {
            if (data == null) return null
            return CalendarFeed(
                items = (data["items"]?.arrayValue ?: emptyList()).mapNotNull { CalItem.from(it) },
                timezone = data["timezone"]?.stringValue ?: "",
            )
        }
    }
}

/** Stored recurrence settings on a native entry (`calendar_entry`). */
data class CalRecurrence(
    val type: String?,          // daily | weekly | monthly | yearly | null
    val interval: Int,
    val daysOfWeek: List<Int>,  // weekly: 0=Sun…6=Sat; monthly by-weekday: single value
    val weekOfMonth: Int?,      // monthly by-weekday: 1-4, -1 = last
    val endDate: String?,       // "yyyy-MM-dd"
) {
    companion object {
        fun from(json: JsonValue?): CalRecurrence {
            val raw = json?.get("days_of_week")?.stringValue ?: ""
            return CalRecurrence(
                type = json?.get("type")?.takeUnless { it.isNull }?.stringValue,
                interval = maxOf(1, json?.get("interval")?.intValue ?: 1),
                daysOfWeek = raw.split(",").mapNotNull { it.trim().toIntOrNull() }.filter { it in 0..6 },
                weekOfMonth = json?.get("week_of_month")?.takeUnless { it.isNull }?.intValue,
                endDate = json?.get("end_date")?.takeUnless { it.isNull }?.stringValue,
            )
        }
    }
}

/** The `calendar_entry` payload — one native entry shaped for the editor. */
data class CalEntryDetail(
    val entryId: Int,
    val title: String,
    val date: String,           // wall-clock "yyyy-MM-dd"
    val startTime: String,      // "HH:mm:ss"
    val endTime: String,
    val timezone: String,       // IANA zone the wall-clock values are in
    val allDay: Boolean,
    val blocksAvailability: Boolean,
    val isRecurringParent: Boolean,
    val recurrenceDescription: String,
    val recurrence: CalRecurrence,
) {
    companion object {
        fun from(data: JsonValue?): CalEntryDetail? {
            val entry = data?.get("entry") ?: return null
            val id = entry["entry_id"]?.intValue ?: return null
            return CalEntryDetail(
                entryId = id,
                title = entry["title"]?.stringValue ?: "",
                date = entry["date"]?.stringValue ?: "",
                startTime = entry["start_time"]?.stringValue ?: "",
                endTime = entry["end_time"]?.stringValue ?: "",
                timezone = entry["timezone"]?.stringValue ?: "",
                allDay = entry["all_day"]?.boolValue ?: false,
                blocksAvailability = entry["blocks_availability"]?.boolValue ?: true,
                isRecurringParent = entry["is_recurring_parent"]?.boolValue ?: false,
                recurrenceDescription = entry["recurrence_description"]?.stringValue ?: "",
                recurrence = CalRecurrence.from(entry["recurrence"]),
            )
        }
    }
}

// MARK: - Date math (pure, unit-tested)

object CalDisplay {
    fun dbFormatter(): SimpleDateFormat =
        SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.US).apply {
            timeZone = TimeZone.getTimeZone("UTC")
        }

    /** Server times are UTC "yyyy-MM-dd HH:mm:ss(.ffffff)". */
    fun date(dbTime: String): Date? {
        if (dbTime.length < 19) return null
        return try {
            dbFormatter().parse(dbTime.substring(0, 19))
        } catch (e: Exception) {
            null
        }
    }

    /** Local day key ("yyyy-MM-dd" in the calendar's zone) for grouping. */
    fun dayKey(date: Date, calendar: Calendar): String {
        val c = calendar.clone() as Calendar
        c.time = date
        return String.format(
            Locale.US, "%04d-%02d-%02d",
            c.get(Calendar.YEAR), c.get(Calendar.MONTH) + 1, c.get(Calendar.DAY_OF_MONTH),
        )
    }

    fun startOfDay(date: Date, calendar: Calendar): Date {
        val c = calendar.clone() as Calendar
        c.time = date
        c.set(Calendar.HOUR_OF_DAY, 0)
        c.set(Calendar.MINUTE, 0)
        c.set(Calendar.SECOND, 0)
        c.set(Calendar.MILLISECOND, 0)
        return c.time
    }

    /** Every local day an item touches. End instants are exclusive, so an
     *  all-day entry ending at the next local midnight stays on one day. */
    fun dayKeys(item: CalItem, calendar: Calendar): List<String> {
        val start = date(item.start) ?: return emptyList()
        val end = date(item.end) ?: start
        val keys = ArrayList<String>()
        var cursor = startOfDay(start, calendar)
        var guardrail = 0
        do {
            keys.add(dayKey(cursor, calendar))
            val c = calendar.clone() as Calendar
            c.time = cursor
            c.add(Calendar.DAY_OF_MONTH, 1)
            cursor = c.time
            guardrail += 1
        } while (cursor.before(end) && guardrail < 62)
        return keys
    }

    /** Agenda ordering: all-day items first, then by start instant. */
    val agendaComparator: Comparator<CalItem> =
        compareByDescending<CalItem> { it.allDay }.thenBy { it.start }.thenBy { it.sourceKey }

    /** "2:00 PM – 3:30 PM" for timed items, "All day" otherwise. */
    fun timeLabel(item: CalItem): String {
        if (item.allDay) return "All day"
        val start = date(item.start) ?: return ""
        val end = date(item.end) ?: return ""
        val f = DateFormat.getTimeInstance(DateFormat.SHORT)
        return "${f.format(start)} – ${f.format(end)}"
    }
}

object CalMonthMath {
    /** The first instant of the month containing [anchor]. */
    fun monthStart(anchor: Date, calendar: Calendar): Date {
        val c = calendar.clone() as Calendar
        c.time = anchor
        c.set(Calendar.DAY_OF_MONTH, 1)
        c.set(Calendar.HOUR_OF_DAY, 0)
        c.set(Calendar.MINUTE, 0)
        c.set(Calendar.SECOND, 0)
        c.set(Calendar.MILLISECOND, 0)
        return c.time
    }

    /** Cells for a month grid honoring the calendar's first weekday:
     *  leading nulls, then one Date per day. */
    fun gridDays(anchor: Date, calendar: Calendar): List<Date?> {
        val first = monthStart(anchor, calendar)
        val c = calendar.clone() as Calendar
        c.time = first
        val dayCount = c.getActualMaximum(Calendar.DAY_OF_MONTH)
        val firstWeekday = c.get(Calendar.DAY_OF_WEEK)
        val leading = (firstWeekday - calendar.firstDayOfWeek + 7) % 7
        val cells = ArrayList<Date?>(leading + dayCount)
        repeat(leading) { cells.add(null) }
        for (day in 0 until dayCount) {
            val d = calendar.clone() as Calendar
            d.time = first
            d.add(Calendar.DAY_OF_MONTH, day)
            cells.add(d.time)
        }
        return cells
    }

    /** Weekday header symbols (single letters) in the calendar's display order. */
    fun weekdaySymbols(calendar: Calendar): List<String> {
        // DateFormatSymbols.shortWeekdays is 1-indexed (1 = Sunday).
        val shorts = DateFormatSymbols().shortWeekdays
        return (0 until 7).map { offset ->
            val weekday = ((calendar.firstDayOfWeek - 1 + offset) % 7) + 1
            shorts[weekday].take(1)
        }
    }

    /** The UTC fetch window for a month view: the month padded a week on
     *  each side, so leading/trailing grid cells have data too. */
    fun fetchWindow(anchor: Date, calendar: Calendar): Pair<String, String> {
        val first = monthStart(anchor, calendar)
        val startCal = calendar.clone() as Calendar
        startCal.time = first
        startCal.add(Calendar.DAY_OF_MONTH, -7)
        val endCal = calendar.clone() as Calendar
        endCal.time = first
        endCal.add(Calendar.MONTH, 1)
        endCal.add(Calendar.DAY_OF_MONTH, 7)
        val f = CalDisplay.dbFormatter()
        return f.format(startCal.time) to f.format(endCal.time)
    }
}
