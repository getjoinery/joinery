package com.getjoinery.calendar

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.gestures.detectHorizontalDragGestures
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.automirrored.filled.KeyboardArrowLeft
import androidx.compose.material.icons.automirrored.filled.KeyboardArrowRight
import androidx.compose.material.icons.filled.Add
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.pulltorefresh.PullToRefreshContainer
import androidx.compose.material3.pulltorefresh.rememberPullToRefreshState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.input.nestedscroll.nestedScroll
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import com.getjoinery.android.ApiClient
import com.getjoinery.android.NativeScreenRegistry
import com.getjoinery.android.WebPageState
import com.getjoinery.android.WebScreen
import com.getjoinery.android.WebSessionCoordinator
import java.text.SimpleDateFormat
import java.util.Calendar
import java.util.Date
import java.util.Locale
import kotlin.math.abs
import kotlinx.coroutines.launch

/**
 * Module entry point: call once at app launch to make the `calendar`
 * navigation screen available. The server flips the Calendar entry to
 * `{type: "native", screen: "calendar"}`; builds without this module keep
 * loading the web calendar via the entry's fallback URL.
 */
object JoineryCalendar {
    fun registerScreens() {
        NativeScreenRegistry.register("calendar") { context ->
            CalendarScreen(client = context.session.client, web = context.web)
        }
    }
}

/** What the editor sheet is opened for. */
sealed class EditorRequest {
    data class Create(val day: Date) : EditorRequest()
    data class Edit(val item: CalItem) : EditorRequest()
}

/**
 * The native personal calendar: a month grid with event dots over a
 * selected-day agenda. Native entries open the entry editor; projected
 * items (events, bookings) open their web page in the authenticated webview.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun CalendarScreen(client: ApiClient, web: WebSessionCoordinator?) {
    val store = remember { CalendarStore(CalendarApi(client)) }
    val scope = rememberCoroutineScope()
    var editorRequest by remember { mutableStateOf<EditorRequest?>(null) }
    var openWeb by remember { mutableStateOf<CalItem?>(null) }

    LaunchedEffect(Unit) {
        if (store.phase is CalendarStore.Phase.Loading) store.initialLoad()
    }

    val webItem = openWeb
    if (webItem != null && web != null && webItem.url != null) {
        WebItemScreen(webItem, client, web, onBack = { openWeb = null })
    } else {
        CalendarScaffold(
            store = store,
            onCreate = { editorRequest = EditorRequest.Create(store.selectedDate) },
            onOpenItem = { item ->
                if (item.isEditableEntry) {
                    editorRequest = EditorRequest.Edit(item)
                } else if (!item.url.isNullOrEmpty() && web != null) {
                    openWeb = item
                }
            },
        )
    }

    editorRequest?.let { request ->
        EntryEditorSheet(
            api = store.api,
            request = request,
            onDismiss = { editorRequest = null },
            onSaved = {
                editorRequest = null
                scope.launch { store.reload() }
            },
        )
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun WebItemScreen(
    item: CalItem,
    client: ApiClient,
    web: WebSessionCoordinator,
    onBack: () -> Unit,
) {
    BackHandler(onBack = onBack)
    val state = remember(item.url) { WebPageState() }
    Scaffold(topBar = {
        TopAppBar(
            title = { Text(state.pageTitle.ifEmpty { item.title }, maxLines = 1, overflow = TextOverflow.Ellipsis) },
            navigationIcon = {
                IconButton(onClick = onBack) {
                    Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "Back")
                }
            },
        )
    }) { padding ->
        WebScreen(item.url!!, client, web, state, Modifier.padding(padding))
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun CalendarScaffold(
    store: CalendarStore,
    onCreate: () -> Unit,
    onOpenItem: (CalItem) -> Unit,
) {
    val scope = rememberCoroutineScope()

    Scaffold(topBar = {
        TopAppBar(
            title = { Text("Calendar") },
            actions = {
                TextButton(
                    onClick = { scope.launch { store.goToToday() } },
                    modifier = Modifier.testTag("cal_today"),
                ) { Text("Today") }
                IconButton(onClick = onCreate, modifier = Modifier.testTag("cal_add")) {
                    Icon(Icons.Filled.Add, contentDescription = "New entry")
                }
            },
        )
    }) { padding ->
        when (val phase = store.phase) {
            is CalendarStore.Phase.Loading ->
                Box(Modifier.fillMaxSize().padding(padding), contentAlignment = Alignment.Center) {
                    CircularProgressIndicator(Modifier.testTag("cal_loading"))
                }
            is CalendarStore.Phase.Failed ->
                Box(Modifier.fillMaxSize().padding(padding).padding(24.dp), contentAlignment = Alignment.Center) {
                    Column(
                        horizontalAlignment = Alignment.CenterHorizontally,
                        verticalArrangement = Arrangement.spacedBy(12.dp),
                    ) {
                        Text(
                            phase.message,
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                            modifier = Modifier.testTag("cal_error"),
                        )
                        Button(
                            onClick = { scope.launch { store.initialLoad() } },
                            modifier = Modifier.testTag("cal_retry"),
                        ) { Text("Try Again") }
                    }
                }
            is CalendarStore.Phase.Loaded ->
                Column(Modifier.fillMaxSize().padding(padding)) {
                    MonthHeader(store)
                    WeekdayHeader(store.calendar)
                    MonthGrid(store)
                    HorizontalDivider()
                    Agenda(store, onOpenItem)
                }
        }
    }
}

// MARK: Month grid

@Composable
private fun MonthHeader(store: CalendarStore) {
    val scope = rememberCoroutineScope()
    Row(
        Modifier.fillMaxWidth().padding(horizontal = 8.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        IconButton(
            onClick = { scope.launch { store.shiftMonth(-1) } },
            modifier = Modifier.testTag("cal_prev_month"),
        ) {
            Icon(Icons.AutoMirrored.Filled.KeyboardArrowLeft, contentDescription = "Previous month")
        }
        Spacer(Modifier.weight(1f))
        Text(
            store.monthTitle,
            style = MaterialTheme.typography.titleMedium,
            modifier = Modifier.testTag("cal_month_title"),
        )
        Spacer(Modifier.weight(1f))
        IconButton(
            onClick = { scope.launch { store.shiftMonth(1) } },
            modifier = Modifier.testTag("cal_next_month"),
        ) {
            Icon(Icons.AutoMirrored.Filled.KeyboardArrowRight, contentDescription = "Next month")
        }
    }
}

@Composable
private fun WeekdayHeader(calendar: Calendar) {
    Row(Modifier.fillMaxWidth().padding(bottom = 4.dp)) {
        CalMonthMath.weekdaySymbols(calendar).forEach { symbol ->
            Text(
                symbol,
                style = MaterialTheme.typography.labelSmall,
                fontWeight = FontWeight.SemiBold,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                modifier = Modifier.weight(1f),
                textAlign = androidx.compose.ui.text.style.TextAlign.Center,
            )
        }
    }
}

@Composable
private fun MonthGrid(store: CalendarStore) {
    val scope = rememberCoroutineScope()
    val cells = CalMonthMath.gridDays(store.displayedMonth, store.calendar)
    val today = remember { Date() }
    val todayKey = CalDisplay.dayKey(today, store.calendar)
    val selectedKey = CalDisplay.dayKey(store.selectedDate, store.calendar)

    // Horizontal swipe flips months (mirrors the iOS drag gesture).
    var dragTotal by remember { mutableStateOf(0f) }
    Column(
        Modifier
            .fillMaxWidth()
            .padding(horizontal = 6.dp)
            .padding(bottom = 8.dp)
            .testTag("cal_grid")
            .pointerInput(Unit) {
                detectHorizontalDragGestures(
                    onDragStart = { dragTotal = 0f },
                    onHorizontalDrag = { _, amount -> dragTotal += amount },
                    onDragEnd = {
                        if (abs(dragTotal) > 80f) {
                            scope.launch { store.shiftMonth(if (dragTotal < 0) 1 else -1) }
                        }
                    },
                )
            },
        verticalArrangement = Arrangement.spacedBy(6.dp),
    ) {
        cells.chunked(7).forEach { week ->
            Row(Modifier.fillMaxWidth()) {
                week.forEach { day ->
                    Box(Modifier.weight(1f)) {
                        if (day != null) {
                            DayCell(
                                day = day,
                                calendar = store.calendar,
                                isSelected = CalDisplay.dayKey(day, store.calendar) == selectedKey,
                                isToday = CalDisplay.dayKey(day, store.calendar) == todayKey,
                                dotColors = store.dotColors(day),
                                onTap = { store.selectedDate = day },
                            )
                        } else {
                            Spacer(Modifier.height(46.dp))
                        }
                    }
                }
                // Pad a trailing short week so cells keep their width.
                repeat(7 - week.size) { Spacer(Modifier.weight(1f)) }
            }
        }
    }
}

/** One month-grid day: the number, selection/today emphasis, event dots. */
@Composable
private fun DayCell(
    day: Date,
    calendar: Calendar,
    isSelected: Boolean,
    isToday: Boolean,
    dotColors: List<String>,
    onTap: () -> Unit,
) {
    val c = calendar.clone() as Calendar
    c.time = day
    val number = c.get(Calendar.DAY_OF_MONTH)
    val accent = MaterialTheme.colorScheme.primary

    Column(
        Modifier.fillMaxWidth().clickable(onClick = onTap),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.spacedBy(3.dp),
    ) {
        Box(
            Modifier
                .size(32.dp)
                .then(
                    when {
                        isSelected -> Modifier.background(accent, CircleShape)
                        isToday -> Modifier.border(1.5.dp, accent, CircleShape)
                        else -> Modifier
                    },
                ),
            contentAlignment = Alignment.Center,
        ) {
            Text(
                number.toString(),
                style = MaterialTheme.typography.bodyMedium,
                fontWeight = if (isSelected || isToday) FontWeight.SemiBold else FontWeight.Normal,
                color = when {
                    isSelected -> MaterialTheme.colorScheme.onPrimary
                    isToday -> accent
                    else -> MaterialTheme.colorScheme.onSurface
                },
            )
        }
        Row(Modifier.height(6.dp), horizontalArrangement = Arrangement.spacedBy(3.dp)) {
            dotColors.forEach { hex ->
                Box(Modifier.size(5.dp).background(parseHexColor(hex), CircleShape))
            }
        }
    }
}

// MARK: Agenda

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun Agenda(store: CalendarStore, onOpenItem: (CalItem) -> Unit) {
    val refreshState = rememberPullToRefreshState()
    if (refreshState.isRefreshing) {
        LaunchedEffect(true) {
            store.reload()
            refreshState.endRefresh()
        }
    }

    val dayLabel = SimpleDateFormat("EEEE, MMMM d", Locale.getDefault())
        .apply { timeZone = store.calendar.timeZone }
        .format(store.selectedDate)
    val items = store.items(store.selectedDate)

    Box(Modifier.fillMaxSize().nestedScroll(refreshState.nestedScrollConnection)) {
        LazyColumn(Modifier.fillMaxSize().testTag("cal_agenda")) {
            item {
                Text(
                    dayLabel,
                    style = MaterialTheme.typography.labelLarge,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                    modifier = Modifier
                        .padding(horizontal = 16.dp, vertical = 8.dp)
                        .testTag("cal_selected_day"),
                )
            }
            if (items.isEmpty()) {
                item {
                    Text(
                        "Nothing on this day.",
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                        modifier = Modifier
                            .padding(horizontal = 16.dp, vertical = 8.dp)
                            .testTag("cal_agenda_empty"),
                    )
                }
            }
            items(items.size, key = { items[it].sourceKey }) { index ->
                AgendaRow(items[index], onOpenItem)
            }
        }
        PullToRefreshContainer(
            state = refreshState,
            modifier = Modifier.align(Alignment.TopCenter),
        )
    }
}

/** One agenda row: color bar, title, time label, and the item's kind when
 *  it isn't a personal entry. */
@Composable
private fun AgendaRow(item: CalItem, onOpenItem: (CalItem) -> Unit) {
    val color = parseHexColor(item.colorHex)
    val tappable = item.isEditableEntry || !item.url.isNullOrEmpty()
    Row(
        Modifier
            .fillMaxWidth()
            .then(if (tappable) Modifier.clickable { onOpenItem(item) } else Modifier)
            .padding(horizontal = 16.dp, vertical = 8.dp)
            .testTag("cal_agenda_row"),
        horizontalArrangement = Arrangement.spacedBy(12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(
            Modifier
                .width(4.dp)
                .height(36.dp)
                .background(color, RoundedCornerShape(2.dp)),
        )
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
            Text(
                item.title.ifEmpty { "Busy" },
                style = MaterialTheme.typography.bodyMedium,
                fontWeight = FontWeight.Medium,
                maxLines = 1,
                overflow = TextOverflow.Ellipsis,
            )
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                Text(
                    CalDisplay.timeLabel(item),
                    style = MaterialTheme.typography.labelSmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
                if (item.type != "personal") {
                    Surface(shape = CircleShape, color = color.copy(alpha = 0.15f)) {
                        Text(
                            item.type.replaceFirstChar { it.uppercase() },
                            style = MaterialTheme.typography.labelSmall,
                            modifier = Modifier.padding(horizontal = 6.dp, vertical = 1.dp),
                        )
                    }
                }
            }
        }
        if (!item.isEditableEntry && !item.url.isNullOrEmpty()) {
            Icon(
                Icons.AutoMirrored.Filled.KeyboardArrowRight,
                contentDescription = null,
                tint = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }
    }
}

/** "#2563eb" → Color. Falls back to gray on malformed input. */
internal fun parseHexColor(hex: String): Color {
    val cleaned = hex.trim().removePrefix("#")
    if (cleaned.length != 6) return Color.Gray
    val value = cleaned.toLongOrNull(16) ?: return Color.Gray
    return Color(
        red = ((value shr 16) and 0xFF) / 255f,
        green = ((value shr 8) and 0xFF) / 255f,
        blue = (value and 0xFF) / 255f,
    )
}
