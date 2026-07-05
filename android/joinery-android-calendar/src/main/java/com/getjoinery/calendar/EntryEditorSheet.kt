package com.getjoinery.calendar

import android.app.DatePickerDialog
import android.app.TimePickerDialog
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.Add
import androidx.compose.material.icons.outlined.Close
import androidx.compose.material.icons.outlined.Remove
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.ExposedDropdownMenuBox
import androidx.compose.material3.ExposedDropdownMenuDefaults
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Surface
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.window.Dialog
import androidx.compose.ui.window.DialogProperties
import com.getjoinery.android.JoineryApiError
import java.text.DateFormat
import java.text.DateFormatSymbols
import java.text.SimpleDateFormat
import java.util.Calendar
import java.util.Date
import java.util.Locale
import java.util.TimeZone
import kotlinx.coroutines.launch

/**
 * Create / edit a native calendar entry: title, date, all-day or timed,
 * availability blocking, and the full recurrence surface (frequency,
 * interval, weekly days, monthly pattern, series end). Recurring edits and
 * deletes ask for scope (this / future / all) exactly like the web form.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun EntryEditorSheet(
    api: CalendarApi,
    request: EditorRequest,
    onDismiss: () -> Unit,
    onSaved: () -> Unit,
) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()

    // Load state for edits (the entry detail is fetched on open).
    var isLoadingDetail by remember { mutableStateOf(request is EditorRequest.Edit) }
    var loadError by remember { mutableStateOf<String?>(null) }

    // Entry fields
    var title by remember { mutableStateOf("") }
    var date by remember {
        mutableStateOf(if (request is EditorRequest.Create) request.day else Date())
    }
    var allDay by remember { mutableStateOf(true) }
    var startMinutes by remember { mutableStateOf(9 * 60) }   // minutes since midnight
    var endMinutes by remember { mutableStateOf(10 * 60) }
    var blocks by remember { mutableStateOf(true) }

    // Recurrence fields
    var repeats by remember { mutableStateOf(false) }
    var frequency by remember { mutableStateOf("weekly") }
    var interval by remember { mutableStateOf(1) }
    var weeklyDays by remember { mutableStateOf<Set<Int>>(emptySet()) }
    var monthlyByWeekday by remember { mutableStateOf(false) }
    var weekOfMonth by remember { mutableStateOf(1) }
    var monthlyDow by remember { mutableStateOf(1) }
    var endsMode by remember { mutableStateOf("never") }      // never | date | count
    var endsDate by remember { mutableStateOf(Date()) }
    var endsCount by remember { mutableStateOf(10) }

    // Edit context
    var entryId by remember { mutableStateOf<Int?>(null) }
    var occurrenceDate by remember { mutableStateOf<String?>(null) } // set → occurrence edit, scope asked
    var isRecurringParent by remember { mutableStateOf(false) }
    var entryTimezone by remember { mutableStateOf<String?>(null) }

    // Save/delete state
    var isSaving by remember { mutableStateOf(false) }
    var saveError by remember { mutableStateOf<String?>(null) }
    var showEditScopeDialog by remember { mutableStateOf(false) }
    var showDeleteScopeDialog by remember { mutableStateOf(false) }
    var showDeleteConfirm by remember { mutableStateOf(false) }

    val dayFormatter = remember { SimpleDateFormat("yyyy-MM-dd", Locale.US) }

    LaunchedEffect(request) {
        val item = (request as? EditorRequest.Edit)?.item
        val id = item?.entryId
        if (item == null || id == null) {
            isLoadingDetail = false
            return@LaunchedEffect
        }
        try {
            val detail = api.entry(id)
            entryId = detail.entryId
            occurrenceDate = item.occurrenceDate
            isRecurringParent = detail.isRecurringParent
            entryTimezone = detail.timezone.ifEmpty { null }
            title = detail.title
            allDay = detail.allDay
            blocks = detail.blocksAvailability
            // For a single occurrence the date shown is the occurrence's day;
            // times come from the series' wall clock.
            val dateString = item.occurrenceDate ?: detail.date
            date = try {
                dayFormatter.parse(dateString) ?: Date()
            } catch (e: Exception) {
                Date()
            }
            parseMinutes(detail.startTime)?.let { startMinutes = it }
            parseMinutes(detail.endTime)?.let { endMinutes = it }
            val type = detail.recurrence.type
            if (type != null) {
                repeats = true
                frequency = type
                interval = detail.recurrence.interval
                if (type == "weekly") {
                    weeklyDays = detail.recurrence.daysOfWeek.toSet()
                } else if (type == "monthly" && detail.recurrence.weekOfMonth != null) {
                    monthlyByWeekday = true
                    weekOfMonth = detail.recurrence.weekOfMonth
                    monthlyDow = detail.recurrence.daysOfWeek.firstOrNull() ?: 1
                }
                val end = detail.recurrence.endDate
                if (end != null) {
                    try {
                        dayFormatter.parse(end)?.let {
                            endsMode = "date"
                            endsDate = it
                        }
                    } catch (e: Exception) {
                    }
                }
            }
        } catch (e: Exception) {
            loadError = (e as? JoineryApiError)?.displayMessage ?: (e.message ?: "Load failed.")
        } finally {
            isLoadingDetail = false
        }
    }

    fun recurrenceInput(saveScope: String?): CalRecurrenceInput? {
        // A 'this occurrence only' save produces a standalone replacement —
        // recurrence settings stay on the series.
        if (!repeats || saveScope == "this") return null
        var days = emptyList<Int>()
        var week: Int? = null
        if (frequency == "weekly") {
            days = weeklyDays.sorted()
        } else if (frequency == "monthly" && monthlyByWeekday) {
            days = listOf(monthlyDow)
            week = weekOfMonth
        }
        val ends = when (endsMode) {
            "date" -> CalRecurrenceInput.Ends.OnDate(dayFormatter.format(endsDate))
            "count" -> CalRecurrenceInput.Ends.AfterCount(endsCount)
            else -> CalRecurrenceInput.Ends.Never
        }
        return CalRecurrenceInput(frequency, interval, days, week, ends)
    }

    fun save(saveScope: String?) {
        isSaving = true
        scope.launch {
            try {
                api.save(
                    entryId = entryId,
                    occurrenceDate = occurrenceDate,
                    scope = saveScope,
                    date = dayFormatter.format(date),
                    title = title.trim(),
                    allDay = allDay,
                    startTime = formatMinutes(startMinutes),
                    endTime = formatMinutes(endMinutes),
                    blocks = blocks,
                    timezone = entryTimezone ?: TimeZone.getDefault().id,
                    recurrence = recurrenceInput(saveScope),
                )
                isSaving = false
                onSaved()
            } catch (e: Exception) {
                isSaving = false
                saveError = (e as? JoineryApiError)?.displayMessage ?: (e.message ?: "Save failed.")
            }
        }
    }

    fun performDelete(deleteScope: String?) {
        val id = entryId ?: return
        isSaving = true
        scope.launch {
            try {
                api.delete(id, deleteScope, occurrenceDate)
                isSaving = false
                onSaved()
            } catch (e: Exception) {
                isSaving = false
                saveError = (e as? JoineryApiError)?.displayMessage ?: (e.message ?: "Delete failed.")
            }
        }
    }

    fun saveTapped() {
        saveError = null
        if (!allDay && endMinutes <= startMinutes) {
            saveError = "The end time must be after the start time."
            return
        }
        if (entryId != null && (occurrenceDate != null || isRecurringParent)) {
            showEditScopeDialog = true
        } else {
            save(null)
        }
    }

    fun deleteTapped() {
        if (occurrenceDate != null || isRecurringParent) {
            showDeleteScopeDialog = true
        } else {
            showDeleteConfirm = true
        }
    }

    fun pickDate(current: Date, onPicked: (Date) -> Unit) {
        val c = Calendar.getInstance()
        c.time = current
        DatePickerDialog(
            context,
            { _, year, month, day ->
                val n = Calendar.getInstance()
                n.clear()
                n.set(year, month, day)
                onPicked(n.time)
            },
            c.get(Calendar.YEAR), c.get(Calendar.MONTH), c.get(Calendar.DAY_OF_MONTH),
        ).show()
    }

    fun pickTime(minutes: Int, onPicked: (Int) -> Unit) {
        TimePickerDialog(
            context,
            { _, hour, minute -> onPicked(hour * 60 + minute) },
            minutes / 60, minutes % 60, false,
        ).show()
    }

    Dialog(
        onDismissRequest = { if (!isSaving) onDismiss() },
        properties = DialogProperties(usePlatformDefaultWidth = false),
    ) {
        Surface(Modifier.fillMaxSize()) {
            Scaffold(topBar = {
                TopAppBar(
                    title = { Text(if (entryId == null) "New Entry" else "Edit Entry") },
                    navigationIcon = {
                        IconButton(
                            onClick = onDismiss,
                            enabled = !isSaving,
                            modifier = Modifier.testTag("cal_entry_cancel"),
                        ) {
                            Icon(Icons.Outlined.Close, contentDescription = "Cancel")
                        }
                    },
                    actions = {
                        TextButton(
                            onClick = { saveTapped() },
                            enabled = !isSaving && !isLoadingDetail,
                            modifier = Modifier.testTag("cal_entry_save"),
                        ) { Text("Save") }
                    },
                )
            }) { padding ->
                when {
                    isLoadingDetail ->
                        Box(Modifier.fillMaxSize().padding(padding), contentAlignment = Alignment.Center) {
                            CircularProgressIndicator()
                        }
                    loadError != null ->
                        Box(Modifier.fillMaxSize().padding(padding).padding(24.dp), contentAlignment = Alignment.Center) {
                            Column(
                                horizontalAlignment = Alignment.CenterHorizontally,
                                verticalArrangement = Arrangement.spacedBy(12.dp),
                            ) {
                                Text(loadError!!, color = MaterialTheme.colorScheme.onSurfaceVariant)
                                Button(onClick = onDismiss) { Text("Close") }
                            }
                        }
                    else ->
                        Column(
                            Modifier
                                .fillMaxSize()
                                .padding(padding)
                                .verticalScroll(rememberScrollState())
                                .padding(16.dp),
                            verticalArrangement = Arrangement.spacedBy(12.dp),
                        ) {
                            saveError?.let {
                                Text(
                                    it,
                                    color = MaterialTheme.colorScheme.error,
                                    modifier = Modifier.testTag("cal_entry_error"),
                                )
                            }
                            occurrenceDate?.let {
                                Text(
                                    "Editing the $it occurrence of a repeating entry.",
                                    style = MaterialTheme.typography.labelMedium,
                                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                                )
                            }
                            OutlinedTextField(
                                value = title,
                                onValueChange = { title = it },
                                label = { Text("Title") },
                                singleLine = true,
                                modifier = Modifier.fillMaxWidth().testTag("cal_entry_title"),
                            )
                            ValueRow(
                                label = "Date",
                                value = DateFormat.getDateInstance(DateFormat.MEDIUM).format(date),
                                tag = "cal_entry_date",
                            ) { pickDate(date) { date = it } }
                            SwitchRow("All day", allDay, "cal_entry_allday") { allDay = it }
                            if (!allDay) {
                                ValueRow("Starts", timeLabel(startMinutes), "cal_entry_start") {
                                    pickTime(startMinutes) { startMinutes = it }
                                }
                                ValueRow("Ends", timeLabel(endMinutes), "cal_entry_end") {
                                    pickTime(endMinutes) { endMinutes = it }
                                }
                            }
                            HorizontalDivider()
                            SwitchRow("Block this time", blocks, "cal_entry_blocks") { blocks = it }
                            Text(
                                "Removes this time from your booking availability.",
                                style = MaterialTheme.typography.labelMedium,
                                color = MaterialTheme.colorScheme.onSurfaceVariant,
                            )
                            HorizontalDivider()
                            SwitchRow("Repeats", repeats, "cal_entry_repeats") { on ->
                                repeats = on
                                if (on && weeklyDays.isEmpty()) {
                                    // Pre-select the entry date's weekday (0=Sun…6=Sat).
                                    val c = Calendar.getInstance()
                                    c.time = date
                                    weeklyDays = setOf(c.get(Calendar.DAY_OF_WEEK) - 1)
                                }
                            }
                            if (repeats) {
                                DropdownRow(
                                    label = "Frequency",
                                    options = listOf(
                                        "daily" to "Daily", "weekly" to "Weekly",
                                        "monthly" to "Monthly", "yearly" to "Yearly",
                                    ),
                                    selected = frequency,
                                ) { frequency = it }
                                StepperRow(
                                    label = "Every $interval ${intervalUnit(frequency, interval)}",
                                    onDecrement = { if (interval > 1) interval -= 1 },
                                    onIncrement = { if (interval < 99) interval += 1 },
                                )
                                if (frequency == "weekly") {
                                    WeekdayChips(weeklyDays) { dow ->
                                        weeklyDays = if (weeklyDays.contains(dow)) {
                                            weeklyDays - dow
                                        } else {
                                            weeklyDays + dow
                                        }
                                    }
                                }
                                if (frequency == "monthly") {
                                    SwitchRow("On a specific weekday", monthlyByWeekday, "cal_entry_monthly_weekday") {
                                        monthlyByWeekday = it
                                    }
                                    if (monthlyByWeekday) {
                                        DropdownRow(
                                            label = "Week",
                                            options = listOf(
                                                "1" to "First", "2" to "Second", "3" to "Third",
                                                "4" to "Fourth", "-1" to "Last",
                                            ),
                                            selected = weekOfMonth.toString(),
                                        ) { weekOfMonth = it.toInt() }
                                        DropdownRow(
                                            label = "Weekday",
                                            options = (0..6).map { dow ->
                                                dow.toString() to DateFormatSymbols().weekdays[dow + 1]
                                            },
                                            selected = monthlyDow.toString(),
                                        ) { monthlyDow = it.toInt() }
                                    }
                                }
                                DropdownRow(
                                    label = "Ends",
                                    options = listOf(
                                        "never" to "Never", "date" to "On date",
                                        "count" to "After a number of times",
                                    ),
                                    selected = endsMode,
                                ) { endsMode = it }
                                if (endsMode == "date") {
                                    ValueRow(
                                        label = "End date",
                                        value = DateFormat.getDateInstance(DateFormat.MEDIUM).format(endsDate),
                                        tag = "cal_entry_ends_date",
                                    ) { pickDate(endsDate) { endsDate = it } }
                                }
                                if (endsMode == "count") {
                                    StepperRow(
                                        label = "After $endsCount occurrences",
                                        onDecrement = { if (endsCount > 1) endsCount -= 1 },
                                        onIncrement = { if (endsCount < 999) endsCount += 1 },
                                    )
                                }
                            }
                            if (entryId != null) {
                                HorizontalDivider()
                                OutlinedButton(
                                    onClick = { deleteTapped() },
                                    enabled = !isSaving,
                                    modifier = Modifier.fillMaxWidth().testTag("cal_entry_delete"),
                                ) {
                                    Text("Delete Entry", color = MaterialTheme.colorScheme.error)
                                }
                            }
                        }
                }
            }
        }
    }

    if (showEditScopeDialog) {
        ScopeDialog(
            title = "Save recurring entry",
            options = listOf(
                "This occurrence only" to "this",
                "This and future occurrences" to "future",
                "All occurrences" to "all",
            ),
            onPick = { showEditScopeDialog = false; save(it) },
            onCancel = { showEditScopeDialog = false },
        )
    }
    if (showDeleteScopeDialog) {
        ScopeDialog(
            title = "Delete recurring entry",
            options = buildList {
                if (occurrenceDate != null) {
                    add("This occurrence only" to "this")
                    add("This and future occurrences" to "future")
                }
                add("All occurrences" to "all")
            },
            destructive = true,
            onPick = { showDeleteScopeDialog = false; performDelete(it) },
            onCancel = { showDeleteScopeDialog = false },
        )
    }
    if (showDeleteConfirm) {
        AlertDialog(
            onDismissRequest = { showDeleteConfirm = false },
            title = { Text("Delete this entry?") },
            confirmButton = {
                TextButton(onClick = { showDeleteConfirm = false; performDelete(null) }) {
                    Text("Delete", color = MaterialTheme.colorScheme.error)
                }
            },
            dismissButton = {
                TextButton(onClick = { showDeleteConfirm = false }) { Text("Cancel") }
            },
        )
    }
}

// MARK: - Rows

@Composable
private fun ValueRow(label: String, value: String, tag: String, onClick: () -> Unit) {
    Row(
        Modifier.fillMaxWidth().clickable(onClick = onClick).padding(vertical = 10.dp).testTag(tag),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text(label, modifier = Modifier.weight(1f))
        Text(value, color = MaterialTheme.colorScheme.primary, fontWeight = FontWeight.Medium)
    }
}

@Composable
private fun SwitchRow(label: String, checked: Boolean, tag: String, onChange: (Boolean) -> Unit) {
    Row(
        Modifier.fillMaxWidth().padding(vertical = 2.dp).testTag(tag),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text(label, modifier = Modifier.weight(1f))
        Switch(checked = checked, onCheckedChange = onChange)
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun DropdownRow(
    label: String,
    options: List<Pair<String, String>>,
    selected: String,
    onSelect: (String) -> Unit,
) {
    var open by remember { mutableStateOf(false) }
    val current = options.firstOrNull { it.first == selected }?.second ?: ""

    ExposedDropdownMenuBox(expanded = open, onExpandedChange = { open = it }) {
        OutlinedTextField(
            value = current,
            onValueChange = {},
            readOnly = true,
            label = { Text(label) },
            trailingIcon = { ExposedDropdownMenuDefaults.TrailingIcon(expanded = open) },
            modifier = Modifier.fillMaxWidth().menuAnchor(),
        )
        ExposedDropdownMenu(expanded = open, onDismissRequest = { open = false }) {
            options.forEach { (value, text) ->
                DropdownMenuItem(
                    text = { Text(text) },
                    onClick = {
                        open = false
                        onSelect(value)
                    },
                )
            }
        }
    }
}

@Composable
private fun StepperRow(label: String, onDecrement: () -> Unit, onIncrement: () -> Unit) {
    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
        Text(label, modifier = Modifier.weight(1f))
        IconButton(onClick = onDecrement) {
            Icon(Icons.Outlined.Remove, contentDescription = "Decrease")
        }
        IconButton(onClick = onIncrement) {
            Icon(Icons.Outlined.Add, contentDescription = "Increase")
        }
    }
}

@Composable
private fun WeekdayChips(selected: Set<Int>, onToggle: (Int) -> Unit) {
    val shorts = DateFormatSymbols().shortWeekdays  // 1-indexed, 1 = Sunday
    Row(
        Modifier.fillMaxWidth(),
        horizontalArrangement = Arrangement.spacedBy(6.dp, Alignment.CenterHorizontally),
    ) {
        (0..6).forEach { dow ->
            val isOn = selected.contains(dow)
            Box(
                Modifier
                    .size(36.dp)
                    .background(
                        if (isOn) MaterialTheme.colorScheme.primary
                        else MaterialTheme.colorScheme.surfaceVariant,
                        CircleShape,
                    )
                    .clickable { onToggle(dow) },
                contentAlignment = Alignment.Center,
            ) {
                Text(
                    shorts[dow + 1].take(1),
                    style = MaterialTheme.typography.labelMedium,
                    fontWeight = FontWeight.SemiBold,
                    color = if (isOn) MaterialTheme.colorScheme.onPrimary
                    else MaterialTheme.colorScheme.onSurface,
                )
            }
        }
    }
}

/** A stacked-option dialog for recurring-edit scope (this / future / all). */
@Composable
private fun ScopeDialog(
    title: String,
    options: List<Pair<String, String>>,
    destructive: Boolean = false,
    onPick: (String) -> Unit,
    onCancel: () -> Unit,
) {
    AlertDialog(
        onDismissRequest = onCancel,
        title = { Text(title) },
        text = {
            Column {
                options.forEach { (label, value) ->
                    TextButton(
                        onClick = { onPick(value) },
                        modifier = Modifier.fillMaxWidth(),
                    ) {
                        Text(
                            label,
                            color = if (destructive) MaterialTheme.colorScheme.error
                            else MaterialTheme.colorScheme.primary,
                        )
                        Spacer(Modifier.weight(1f))
                    }
                }
            }
        },
        confirmButton = {},
        dismissButton = {
            TextButton(onClick = onCancel) { Text("Cancel") }
        },
    )
}

// MARK: - Helpers

private fun parseMinutes(hms: String): Int? {
    val parts = hms.split(":").mapNotNull { it.toIntOrNull() }
    if (parts.size < 2) return null
    return parts[0] * 60 + parts[1]
}

private fun formatMinutes(minutes: Int): String =
    String.format(Locale.US, "%02d:%02d", minutes / 60, minutes % 60)

private fun timeLabel(minutes: Int): String {
    val c = Calendar.getInstance()
    c.set(Calendar.HOUR_OF_DAY, minutes / 60)
    c.set(Calendar.MINUTE, minutes % 60)
    return DateFormat.getTimeInstance(DateFormat.SHORT).format(c.time)
}

private fun intervalUnit(frequency: String, interval: Int): String {
    val unit = when (frequency) {
        "daily" -> "day"
        "weekly" -> "week"
        "monthly" -> "month"
        else -> "year"
    }
    return if (interval == 1) unit else unit + "s"
}
