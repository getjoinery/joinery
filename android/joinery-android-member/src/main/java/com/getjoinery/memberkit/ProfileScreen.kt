package com.getjoinery.memberkit

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.automirrored.filled.KeyboardArrowRight
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.pulltorefresh.PullToRefreshContainer
import androidx.compose.material3.pulltorefresh.rememberPullToRefreshState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.input.nestedscroll.nestedScroll
import androidx.compose.ui.platform.LocalLifecycleOwner
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleEventObserver
import com.getjoinery.android.ApiClient
import com.getjoinery.android.NativeScreenRegistry
import com.getjoinery.android.WebPageState
import com.getjoinery.android.WebScreen
import com.getjoinery.android.WebSessionCoordinator
import kotlinx.coroutines.launch

/**
 * Module entry point: call once at app launch to make the member-surface
 * native screens available. The server flips each menu entry to
 * `{type: "native", screen: "..."}`; builds without this module keep loading
 * the matching web page via the entry's fallback URL.
 */
object JoineryMember {
    /** The screen names this module owns, kept adjacent to registration so
     *  [registerScreens] and [unregisterScreens] can't drift. */
    private val SCREENS = listOf("profile", "orders", "subscriptions", "events", "conversations", "security")

    fun registerScreens() {
        NativeScreenRegistry.register("profile") { ctx ->
            ProfileScreen(ctx.session.client, ctx.web, onBack = ctx.onExit)
        }
        NativeScreenRegistry.register("orders") { ctx ->
            OrdersScreen(ctx.session.client, onBack = ctx.onExit)
        }
        NativeScreenRegistry.register("subscriptions") { ctx ->
            SubscriptionsScreen(ctx.session.client, ctx.web, onBack = ctx.onExit)
        }
        NativeScreenRegistry.register("events") { ctx ->
            EventsScreen(ctx.session.client, ctx.web, onBack = ctx.onExit)
        }
        NativeScreenRegistry.register("conversations") { ctx ->
            ConversationsScreen(ctx.session.client, onBack = ctx.onExit)
        }
        NativeScreenRegistry.register("security") { ctx ->
            SecurityScreen(ctx.session.client, ctx.web, onBack = ctx.onExit)
        }
    }

    /** Remove the member screens so their entries resolve to the web fallback.
     *  Used by the version-safe fallback path (and its instrumented proof) —
     *  making the "no module" state authoritative regardless of what ran
     *  earlier in the process. */
    fun unregisterScreens() {
        SCREENS.forEach { NativeScreenRegistry.unregister(it) }
    }
}

/** Where the profile dashboard can navigate on a tap. Native tiles go to the
 *  module's own screens; deliberately-web surfaces open through [web]. */
private sealed class ProfileChild {
    object Orders : ProfileChild()
    object Subscriptions : ProfileChild()
    object Events : ProfileChild()
    object Conversations : ProfileChild()
    object Security : ProfileChild()
    data class Web(val title: String, val target: String) : ProfileChild()
}

/**
 * The member dashboard: user card, an alert row for anything needing
 * attention, stat tiles, and recent-item lists. Every section renders only
 * from keys the server actually sent — a settings-gated section (messaging,
 * products, subscriptions off) is simply absent, not an empty placeholder.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ProfileScreen(client: ApiClient, web: WebSessionCoordinator?, onBack: (() -> Unit)? = null) {
    val store = remember { ProfileStore(MemberApi(client)) }
    val scope = rememberCoroutineScope()
    var child by remember { mutableStateOf<ProfileChild?>(null) }

    LaunchedEffect(Unit) {
        if (store.phase is MemberPhase.Loading) store.initialLoad()
    }

    // Foreground refresh so counts (unread, upcoming) stay current; skips the
    // initial resume and any time a child screen owns the view.
    val lifecycleOwner = LocalLifecycleOwner.current
    DisposableEffect(lifecycleOwner) {
        val observer = LifecycleEventObserver { _, event ->
            if (event == Lifecycle.Event.ON_RESUME &&
                child == null && store.phase !is MemberPhase.Loading
            ) {
                scope.launch { store.reload() }
            }
        }
        lifecycleOwner.lifecycle.addObserver(observer)
        onDispose { lifecycleOwner.lifecycle.removeObserver(observer) }
    }

    val active = child
    if (active != null) {
        // A child owns the screen; returning reloads the dashboard so a
        // cancel/withdraw/mute done inside it shows up in the counts.
        val back = { child = null; scope.launch { store.reload() }; Unit }
        BackHandler(onBack = back)
        when (active) {
            is ProfileChild.Orders -> OrdersScreen(client, onBack = back)
            is ProfileChild.Subscriptions -> SubscriptionsScreen(client, web, onBack = back)
            is ProfileChild.Events -> EventsScreen(client, web, onBack = back)
            is ProfileChild.Conversations -> ConversationsScreen(client, onBack = back)
            is ProfileChild.Security -> SecurityScreen(client, web, onBack = back)
            is ProfileChild.Web -> MemberWebDetail(active.title, active.target, client, web, onBack = back)
        }
        return
    }

    Scaffold(topBar = { MemberTopBar("Profile", onBack) }) { padding ->
        when (val phase = store.phase) {
            is MemberPhase.Loading ->
                Box(Modifier.fillMaxSize().padding(padding), contentAlignment = Alignment.Center) {
                    CircularProgressIndicator(Modifier.testTag("member_profile_loading"))
                }
            is MemberPhase.Failed ->
                RetryBox(phase.message, "member_profile_error", "member_profile_retry", Modifier.padding(padding)) {
                    scope.launch { store.initialLoad() }
                }
            is MemberPhase.Loaded ->
                store.summary?.let { summary ->
                    MemberPullRefresh(onRefresh = { store.reload() }, Modifier.padding(padding)) {
                        Dashboard(summary, web) { child = it }
                    }
                }
        }
    }
}

@Composable
private fun Dashboard(
    summary: DashboardSummary,
    web: WebSessionCoordinator?,
    navigate: (ProfileChild) -> Unit,
) {
    LazyColumn(Modifier.fillMaxSize().testTag("member_profile_dashboard")) {
        item { UserCard(summary) }
        item { HorizontalDivider() }

        val unread = summary.unreadConversationCount ?: 0
        if (summary.pendingSurveys.isNotEmpty() || unread > 0) {
            item { SectionHeader("Needs Attention") }
            if (unread > 0) {
                item {
                    val label = if (unread == 1) "1 unread message" else "$unread unread messages"
                    DashboardRow(label, "member_profile_alert_unread", chevron = true) {
                        navigate(ProfileChild.Conversations)
                    }
                }
            }
            summary.pendingSurveys.forEach { survey ->
                item {
                    DashboardRow("Survey pending: ${survey.eventName}", "member_profile_survey_${survey.surveyId}", chevron = web != null) {
                        if (web != null) {
                            navigate(
                                ProfileChild.Web(
                                    "Survey",
                                    "/survey?survey_id=${survey.surveyId}&event_id=${survey.eventId}",
                                ),
                            )
                        }
                    }
                }
            }
            item { HorizontalDivider() }
        }

        // Stat tiles — each routes natively to the module's own screen.
        item {
            TileRow("Upcoming Events", summary.upcomingEventCount.toString(), "member_profile_tile_events") {
                navigate(ProfileChild.Events)
            }
        }
        if (summary.subscriptionsActive) {
            item {
                TileRow(
                    "Active Subscriptions",
                    (summary.activeSubscriptionCount ?: 0).toString(),
                    "member_profile_tile_subscriptions",
                ) { navigate(ProfileChild.Subscriptions) }
            }
        }
        if (summary.productsActive) {
            item { DashboardRow("Orders", "member_profile_tile_orders", chevron = true) { navigate(ProfileChild.Orders) } }
        }
        if (summary.messagingActive) {
            item { DashboardRow("Messages", "member_profile_tile_conversations", chevron = true) { navigate(ProfileChild.Conversations) } }
        }
        item { DashboardRow("Security", "member_profile_tile_security", chevron = true) { navigate(ProfileChild.Security) } }

        if (summary.upcomingEvents.isNotEmpty()) {
            item { HorizontalDivider() }
            item { SectionHeader("Upcoming Events") }
            summary.upcomingEvents.forEach { event ->
                item {
                    RecentRow(
                        title = event.eventName,
                        subtitle = event.nextSessionTime?.let { MemberDisplay.dateTimeLabel(it) } ?: "",
                        chevron = web != null,
                    ) {
                        if (web != null) navigate(ProfileChild.Web(event.eventName, event.webUrl))
                    }
                }
            }
            item { DashboardRow("See all events", "member_profile_all_events", chevron = true) { navigate(ProfileChild.Events) } }
        }

        summary.recentConversations?.takeIf { it.isNotEmpty() }?.let { conversations ->
            item { HorizontalDivider() }
            item { SectionHeader("Recent Conversations") }
            conversations.forEach { conversation ->
                item {
                    RecentRow(
                        title = conversation.otherDisplayName,
                        subtitle = conversation.preview,
                        bold = conversation.unread,
                        chevron = true,
                    ) { navigate(ProfileChild.Conversations) }
                }
            }
            item { DashboardRow("See all messages", "member_profile_all_messages", chevron = true) { navigate(ProfileChild.Conversations) } }
        }

        summary.recentOrders?.takeIf { it.isNotEmpty() }?.let { orders ->
            item { HorizontalDivider() }
            item { SectionHeader("Recent Orders") }
            orders.forEach { order ->
                item { LabeledRow(MemberDisplay.dateLabel(order.date), "$${order.total}") }
            }
            item { DashboardRow("See all orders", "member_profile_all_orders", chevron = true) { navigate(ProfileChild.Orders) } }
        }

        summary.recentSubscriptions?.takeIf { it.isNotEmpty() }?.let { subs ->
            item { HorizontalDivider() }
            item { SectionHeader("Subscriptions") }
            subs.forEach { sub ->
                item { LabeledRow(sub.productName, sub.status.replaceFirstChar { it.uppercase() }) }
            }
            item { DashboardRow("See all subscriptions", "member_profile_all_subscriptions", chevron = true) { navigate(ProfileChild.Subscriptions) } }
        }

        if (summary.mailingLists.isNotEmpty()) {
            item { HorizontalDivider() }
            item { SectionHeader("Mailing Lists") }
            summary.mailingLists.forEach { name ->
                item {
                    Text(
                        name,
                        style = MaterialTheme.typography.bodyMedium,
                        modifier = Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 10.dp),
                    )
                }
            }
        }

        if (web != null) {
            item { HorizontalDivider() }
            item {
                DashboardRow("Notifications", "member_profile_notifications", chevron = true) {
                    navigate(ProfileChild.Web("Notifications", "/notifications"))
                }
            }
        }
    }
}

@Composable
private fun UserCard(summary: DashboardSummary) {
    Column(Modifier.fillMaxWidth().padding(16.dp), verticalArrangement = Arrangement.spacedBy(3.dp)) {
        Text(
            summary.userName.ifEmpty { summary.userEmail },
            style = MaterialTheme.typography.titleMedium,
            fontWeight = FontWeight.Bold,
            modifier = Modifier.testTag("member_profile_user_name"),
        )
        Text(summary.userEmail, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.outline)
        if (summary.address.isNotEmpty()) {
            Text(summary.address, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.outline)
        }
    }
}

@Composable
private fun SectionHeader(text: String) {
    Text(
        text,
        style = MaterialTheme.typography.labelMedium,
        color = MaterialTheme.colorScheme.primary,
        modifier = Modifier.padding(start = 16.dp, top = 12.dp, bottom = 4.dp),
    )
}

@Composable
private fun DashboardRow(label: String, tag: String, chevron: Boolean, onClick: () -> Unit) {
    Row(
        Modifier.fillMaxWidth().clickable(onClick = onClick).padding(horizontal = 16.dp, vertical = 14.dp).testTag(tag),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text(label, style = MaterialTheme.typography.bodyLarge, modifier = Modifier.weight(1f))
        if (chevron) {
            Icon(
                Icons.AutoMirrored.Filled.KeyboardArrowRight,
                contentDescription = null,
                tint = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }
    }
}

@Composable
private fun TileRow(label: String, value: String, tag: String, onClick: () -> Unit) {
    Row(
        Modifier.fillMaxWidth().clickable(onClick = onClick).padding(horizontal = 16.dp, vertical = 14.dp).testTag(tag),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text(label, style = MaterialTheme.typography.bodyLarge, modifier = Modifier.weight(1f))
        Text(value, style = MaterialTheme.typography.bodyLarge, fontWeight = FontWeight.SemiBold)
        Spacer(Modifier.padding(4.dp))
        Icon(
            Icons.AutoMirrored.Filled.KeyboardArrowRight,
            contentDescription = null,
            tint = MaterialTheme.colorScheme.onSurfaceVariant,
        )
    }
}

@Composable
private fun LabeledRow(label: String, value: String) {
    Row(
        Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 10.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text(label, style = MaterialTheme.typography.bodyMedium, modifier = Modifier.weight(1f))
        Text(value, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
    }
}

@Composable
private fun RecentRow(
    title: String,
    subtitle: String,
    bold: Boolean = false,
    chevron: Boolean,
    onClick: () -> Unit,
) {
    Row(
        Modifier.fillMaxWidth().clickable(onClick = onClick).padding(horizontal = 16.dp, vertical = 10.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
            Text(
                title,
                style = MaterialTheme.typography.bodyMedium,
                fontWeight = if (bold) FontWeight.SemiBold else FontWeight.Normal,
                maxLines = 1,
                overflow = TextOverflow.Ellipsis,
            )
            if (subtitle.isNotEmpty()) {
                Text(
                    subtitle,
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis,
                )
            }
        }
        if (chevron) {
            Icon(
                Icons.AutoMirrored.Filled.KeyboardArrowRight,
                contentDescription = null,
                tint = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }
    }
}

// MARK: - Shared helpers for the member screens

/**
 * Pull-to-refresh wrapper shared by every member screen, matching the mail
 * module's pattern. [onRefresh] calls the store's generation-guarded reload,
 * which keeps the last-good list on screen while it runs (never blanks it).
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun MemberPullRefresh(
    onRefresh: suspend () -> Unit,
    modifier: Modifier = Modifier,
    content: @Composable () -> Unit,
) {
    val refreshState = rememberPullToRefreshState()
    if (refreshState.isRefreshing) {
        LaunchedEffect(true) {
            onRefresh()
            refreshState.endRefresh()
        }
    }
    Box(modifier.fillMaxSize().nestedScroll(refreshState.nestedScrollConnection)) {
        content()
        PullToRefreshContainer(state = refreshState, modifier = Modifier.align(Alignment.TopCenter))
    }
}

/**
 * The ON_RESUME refresh shared by the standalone member screens: re-read on
 * foreground so state changed elsewhere (a revoked session, a cancelled sub)
 * shows up, skipping the initial resume and while a load is already running.
 */
@Composable
internal fun MemberResumeRefresh(isLoading: Boolean, onResume: () -> Unit) {
    val owner = LocalLifecycleOwner.current
    DisposableEffect(owner) {
        val observer = LifecycleEventObserver { _, event ->
            if (event == Lifecycle.Event.ON_RESUME && !isLoading) onResume()
        }
        owner.lifecycle.addObserver(observer)
        onDispose { owner.lifecycle.removeObserver(observer) }
    }
}

/** The standard retry-on-failure box shared by every member screen. */
@Composable
internal fun RetryBox(
    message: String,
    errorTag: String,
    retryTag: String,
    modifier: Modifier = Modifier,
    onRetry: () -> Unit,
) {
    Box(modifier.fillMaxSize().padding(24.dp), contentAlignment = Alignment.Center) {
        Column(
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            Text(
                message,
                textAlign = TextAlign.Center,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                modifier = Modifier.testTag(errorTag),
            )
            Button(onClick = onRetry, modifier = Modifier.testTag(retryTag)) { Text("Try Again") }
        }
    }
}

/** A deliberately-web surface reached from a native member screen: opens the
 *  authenticated webview (bridged) with native chrome and a back arrow. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun MemberWebDetail(
    title: String,
    target: String,
    client: ApiClient,
    web: WebSessionCoordinator?,
    onBack: () -> Unit,
) {
    BackHandler(onBack = onBack)
    if (web == null) {
        onBack()
        return
    }
    val state = remember(target) { WebPageState() }
    Scaffold(topBar = {
        TopAppBar(
            title = { Text(state.pageTitle.ifEmpty { title }, maxLines = 1, overflow = TextOverflow.Ellipsis) },
            navigationIcon = {
                IconButton(onClick = onBack) {
                    Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "Back")
                }
            },
        )
    }) { padding ->
        WebScreen(target, client, web, state, Modifier.padding(padding))
    }
}

/** A top app bar with an optional back arrow, shared by the standalone member
 *  screens (shown with an arrow when pushed from the dashboard, without one
 *  when reached as a navigation root). */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun MemberTopBar(title: String, onBack: (() -> Unit)?, actions: @Composable () -> Unit = {}) {
    TopAppBar(
        title = { Text(title, maxLines = 1, overflow = TextOverflow.Ellipsis) },
        navigationIcon = {
            if (onBack != null) {
                IconButton(onClick = onBack) {
                    Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "Back")
                }
            }
        },
        actions = { actions() },
    )
}
