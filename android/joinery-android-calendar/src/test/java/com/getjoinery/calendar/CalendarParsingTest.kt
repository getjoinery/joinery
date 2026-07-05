package com.getjoinery.calendar

import com.getjoinery.android.JsonValue
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import java.util.Calendar
import java.util.TimeZone

/** Parsing tests over live calendar action payloads captured from dev (the
 *  fixtures dir holds verbatim API envelopes — the same files backing the iOS
 *  JoineryCalendarKit tests) plus the pure month math behind the grid. */
class CalendarParsingTest {

    private fun fixtureData(name: String): JsonValue {
        val stream = FixtureAnchor::class.java.classLoader!!
            .getResourceAsStream("fixtures/$name.json")
            ?: error("fixture not found: fixtures/$name.json")
        val envelope = JsonValue.parse(stream.readBytes().toString(Charsets.UTF_8))
        return envelope["data"] ?: error("fixture $name has no data")
    }

    /** A fixed calendar so day grouping is deterministic regardless of the
     *  machine running the tests. */
    private fun utcCalendar(): Calendar =
        Calendar.getInstance(TimeZone.getTimeZone("UTC")).apply {
            firstDayOfWeek = Calendar.SUNDAY
        }

    // MARK: Fixtures

    @Test
    fun feedFixtureParses() {
        val feed = CalendarFeed.from(fixtureData("calendar_feed"))!!
        assertTrue(feed.timezone.isNotEmpty())
        assertEquals(4, feed.items.size)

        // The standalone timed entry: edit coordinates but no occurrence.
        val standalone = feed.items.first { it.title.contains("standalone") }
        assertFalse(standalone.allDay)
        assertNotNull(standalone.entryId)
        assertNull(standalone.occurrenceDate)
        assertTrue(standalone.isEditableEntry)
        assertFalse(standalone.isRecurringOccurrence)

        // Recurring occurrences: same parent id, distinct occurrence dates.
        val occurrences = feed.items.filter { it.occurrenceDate != null }
        assertEquals(2, occurrences.size)
        assertEquals(1, occurrences.mapNotNull { it.entryId }.toSet().size)
        assertEquals(2, occurrences.map { it.sourceKey }.toSet().size)
        for (occ in occurrences) {
            assertTrue(occ.allDay)
            assertTrue(occ.isRecurringOccurrence)
        }
    }

    @Test
    fun entryFixtureParses() {
        val detail = CalEntryDetail.from(fixtureData("calendar_entry"))!!
        assertEquals("CalAPI Probe weekly", detail.title)
        assertTrue(detail.allDay)
        assertTrue(detail.isRecurringParent)
        assertTrue(detail.recurrenceDescription.isNotEmpty())
        assertEquals("weekly", detail.recurrence.type)
        assertEquals(1, detail.recurrence.interval)
        assertEquals(listOf(1, 3), detail.recurrence.daysOfWeek)
        assertNotNull(detail.recurrence.endDate)
    }

    // MARK: Day grouping

    @Test
    fun dayKeysSingleTimedItem() {
        val feed = CalendarFeed.from(fixtureData("calendar_feed"))!!
        val standalone = feed.items.first { it.title.contains("standalone") }
        // 2026-07-10 19:00–20:30 UTC stays on one UTC day.
        assertEquals(listOf("2026-07-10"), CalDisplay.dayKeys(standalone, utcCalendar()))
    }

    @Test
    fun dayKeysAllDayStaysOnOneDay() {
        // An all-day New-York entry spans 04:00 → next-day 04:00 UTC; in a
        // New-York calendar it must group onto exactly its own day.
        val nyCal = Calendar.getInstance(TimeZone.getTimeZone("America/New_York"))
        val feed = CalendarFeed.from(fixtureData("calendar_feed"))!!
        val occ = feed.items.first { it.occurrenceDate == "2026-07-06" }
        assertEquals(listOf("2026-07-06"), CalDisplay.dayKeys(occ, nyCal))
    }

    @Test
    fun agendaSortAllDayFirstThenStart() {
        val feed = CalendarFeed.from(fixtureData("calendar_feed"))!!
        val sorted = feed.items.sortedWith(CalDisplay.agendaComparator)
        assertTrue(sorted.first().allDay)
        val timedStarts = sorted.filter { !it.allDay }.map { it.start }
        assertEquals(timedStarts.sorted(), timedStarts)
    }

    // MARK: Month math

    @Test
    fun gridDaysJuly2026() {
        val cal = utcCalendar()
        val july = CalDisplay.date("2026-07-15 12:00:00")!!
        val cells = CalMonthMath.gridDays(july, cal)
        // July 1, 2026 is a Wednesday → 3 leading blanks with a Sunday start.
        assertEquals(0, cells.take(3).filterNotNull().size)
        assertEquals(31, cells.filterNotNull().size)
        val firstDay = cells.filterNotNull().first()
        val c = utcCalendar().apply { time = firstDay }
        assertEquals(1, c.get(Calendar.DAY_OF_MONTH))
        assertEquals(Calendar.JULY, c.get(Calendar.MONTH))
    }

    @Test
    fun gridDaysHonorsFirstWeekday() {
        val cal = utcCalendar().apply { firstDayOfWeek = Calendar.MONDAY }
        val july = CalDisplay.date("2026-07-15 12:00:00")!!
        val cells = CalMonthMath.gridDays(july, cal)
        // Monday start → Wednesday July 1 has 2 leading blanks.
        assertNull(cells[0])
        assertNull(cells[1])
        assertNotNull(cells[2])
    }

    @Test
    fun fetchWindowPadsAWeekEachSide() {
        val cal = utcCalendar()
        val july = CalDisplay.date("2026-07-15 12:00:00")!!
        val window = CalMonthMath.fetchWindow(july, cal)
        assertEquals("2026-06-24 00:00:00", window.first)
        assertEquals("2026-08-08 00:00:00", window.second)
    }

    @Test
    fun weekdaySymbolsRotateWithFirstWeekday() {
        val cal = utcCalendar().apply { firstDayOfWeek = Calendar.MONDAY }
        val symbols = CalMonthMath.weekdaySymbols(cal)
        assertEquals(7, symbols.size)
        // Monday first, Sunday last.
        assertEquals("M", symbols.first())
        assertEquals("S", symbols.last())
    }

    // MARK: Recurrence input encoding

    @Test
    fun recurrenceInputEncodesWeeklyCount() {
        val input = CalRecurrenceInput(
            type = "weekly", interval = 2, daysOfWeek = listOf(1, 3),
            ends = CalRecurrenceInput.Ends.AfterCount(4),
        )
        val json = input.jsonValue()
        assertEquals("weekly", json["type"]?.stringValue)
        assertEquals(2, json["interval"]?.intValue)
        assertEquals(listOf(1, 3), json["days_of_week"]?.arrayValue?.mapNotNull { it.intValue })
        assertEquals("count", json["ends"]?.stringValue)
        assertEquals(4, json["count"]?.intValue)
        assertNull(json["end_date"])
    }

    @Test
    fun recurrenceInputEncodesMonthlyByWeekday() {
        val input = CalRecurrenceInput(
            type = "monthly", daysOfWeek = listOf(2), weekOfMonth = -1,
            ends = CalRecurrenceInput.Ends.OnDate("2026-12-31"),
        )
        val json = input.jsonValue()
        assertEquals(-1, json["week_of_month"]?.intValue)
        assertEquals(listOf(2), json["days_of_week"]?.arrayValue?.mapNotNull { it.intValue })
        assertEquals("date", json["ends"]?.stringValue)
        assertEquals("2026-12-31", json["end_date"]?.stringValue)
    }
}

private class FixtureAnchor
