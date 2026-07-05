package com.getjoinery.calendar

import com.getjoinery.android.ApiClient
import com.getjoinery.android.JoineryApiError
import com.getjoinery.android.JsonValue

/** What the editor sends for a repeating entry. */
data class CalRecurrenceInput(
    val type: String,           // daily | weekly | monthly | yearly
    val interval: Int = 1,
    val daysOfWeek: List<Int> = emptyList(),  // weekly: 0=Sun…6=Sat
    val weekOfMonth: Int? = null,             // monthly by-weekday: 1-4, -1 = last
    val ends: Ends = Ends.Never,
) {
    sealed class Ends {
        object Never : Ends()
        data class OnDate(val date: String) : Ends()   // "yyyy-MM-dd"
        data class AfterCount(val count: Int) : Ends()
    }

    fun jsonValue(): JsonValue {
        val body = ArrayList<Pair<String, JsonValue>>()
        body.add("type" to JsonValue.Str(type))
        body.add("interval" to JsonValue.Num(maxOf(1, interval).toDouble()))
        if (daysOfWeek.isNotEmpty()) {
            body.add("days_of_week" to JsonValue.Arr(daysOfWeek.map { JsonValue.Num(it.toDouble()) }))
        }
        if (weekOfMonth != null) {
            body.add("week_of_month" to JsonValue.Num(weekOfMonth.toDouble()))
        }
        when (ends) {
            is Ends.Never -> body.add("ends" to JsonValue.Str("never"))
            is Ends.OnDate -> {
                body.add("ends" to JsonValue.Str("date"))
                body.add("end_date" to JsonValue.Str(ends.date))
            }
            is Ends.AfterCount -> {
                body.add("ends" to JsonValue.Str("count"))
                body.add("count" to JsonValue.Num(ends.count.toDouble()))
            }
        }
        return JsonValue.Obj(body)
    }
}

/**
 * Thin typed face over the core calendar actions (calendar_feed,
 * calendar_entry, calendar_entry_save, calendar_entry_delete). Ownership is
 * enforced server-side; every call rides the app's session key.
 */
class CalendarApi(val client: ApiClient) {

    suspend fun feed(startUtc: String, endUtc: String): CalendarFeed {
        val envelope = client.submitAction(
            "calendar_feed",
            JsonValue.obj("start" to JsonValue.Str(startUtc), "end" to JsonValue.Str(endUtc)),
        )
        return CalendarFeed.from(envelope["data"]) ?: throw JoineryApiError.Malformed
    }

    suspend fun entry(id: Int): CalEntryDetail {
        val envelope = client.submitAction(
            "calendar_entry",
            JsonValue.obj("entry_id" to JsonValue.Num(id.toDouble())),
        )
        return CalEntryDetail.from(envelope["data"]) ?: throw JoineryApiError.Malformed
    }

    /** Create or update. [scope] + [occurrenceDate] drive the recurring
     *  series semantics exactly as on the web (this / future / all). */
    suspend fun save(
        entryId: Int?,
        occurrenceDate: String?,
        scope: String?,
        date: String,
        title: String,
        allDay: Boolean,
        startTime: String?,
        endTime: String?,
        blocks: Boolean,
        timezone: String,
        recurrence: CalRecurrenceInput?,
    ): Int {
        val body = ArrayList<Pair<String, JsonValue>>()
        body.add("date" to JsonValue.Str(date))
        body.add("title" to JsonValue.Str(title))
        body.add("all_day" to JsonValue.Bool(allDay))
        body.add("blocks" to JsonValue.Bool(blocks))
        body.add("timezone" to JsonValue.Str(timezone))
        if (entryId != null) body.add("entry_id" to JsonValue.Num(entryId.toDouble()))
        if (occurrenceDate != null) body.add("occurrence_date" to JsonValue.Str(occurrenceDate))
        if (scope != null) body.add("scope" to JsonValue.Str(scope))
        if (!allDay) {
            body.add("start_time" to JsonValue.Str(startTime ?: ""))
            body.add("end_time" to JsonValue.Str(endTime ?: ""))
        }
        if (recurrence != null) body.add("recurrence" to recurrence.jsonValue())
        val envelope = client.submitAction("calendar_entry_save", JsonValue.Obj(body))
        return envelope["data"]?.get("entry_id")?.intValue ?: entryId ?: 0
    }

    suspend fun delete(entryId: Int, scope: String?, occurrenceDate: String?) {
        val body = ArrayList<Pair<String, JsonValue>>()
        body.add("entry_id" to JsonValue.Num(entryId.toDouble()))
        if (scope != null) body.add("scope" to JsonValue.Str(scope))
        if (occurrenceDate != null) body.add("occurrence_date" to JsonValue.Str(occurrenceDate))
        client.submitAction("calendar_entry_delete", JsonValue.Obj(body))
    }
}
