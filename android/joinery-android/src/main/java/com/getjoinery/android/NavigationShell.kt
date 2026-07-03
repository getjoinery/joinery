package com.getjoinery.android

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.filled.MoreHoriz
import androidx.compose.material.icons.filled.Settings
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalLifecycleOwner
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleEventObserver
import kotlinx.coroutines.launch

/**
 * The signed-in surface: a bottom bar of server-pinned entries plus a More tab
 * holding everything else and the native Settings screen. Fed entirely by
 * `GET /api/v1/app/navigation`, so menu changes (new plugin pages, retitles,
 * tab re-pinning) reach shipped apps with no release.
 *
 * The shell refreshes the user summary and the navigation table on every
 * foreground, so menu changes appear and a session revoked from the web signs
 * the app out without a relaunch (both calls 401 → the client handler signs
 * out).
 */
@Composable
fun NavigationShell(session: SessionController, user: UserSummary) {
    val store = remember { NavigationStore(session.client) }
    val web = remember { WebSessionCoordinator(session.client) }
    val scope = rememberCoroutineScope()

    // Sign-out drops the bridged web session (cookies + storage) with the API key.
    DisposableEffect(Unit) {
        session.onSignOut = { web.reset() }
        onDispose { session.onSignOut = null }
    }

    LaunchedEffect(Unit) { store.load() }

    // Foreground: pick up menu changes and notice a revoked session. Skips the
    // initial resume (the LaunchedEffect above already loaded).
    val lifecycleOwner = LocalLifecycleOwner.current
    DisposableEffect(lifecycleOwner) {
        val observer = LifecycleEventObserver { _, event ->
            if (event == Lifecycle.Event.ON_RESUME && store.phase !is NavigationStore.Phase.Loading) {
                scope.launch {
                    session.refreshUser()
                    store.refresh()
                }
            }
        }
        lifecycleOwner.lifecycle.addObserver(observer)
        onDispose { lifecycleOwner.lifecycle.removeObserver(observer) }
    }

    when (val phase = store.phase) {
        is NavigationStore.Phase.Loading ->
            Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                CircularProgressIndicator(Modifier.testTag("nav_loading"))
            }
        is NavigationStore.Phase.Failed ->
            Box(Modifier.fillMaxSize().padding(24.dp), contentAlignment = Alignment.Center) {
                Column(
                    horizontalAlignment = Alignment.CenterHorizontally,
                    verticalArrangement = Arrangement.spacedBy(12.dp),
                ) {
                    Text(
                        phase.message,
                        textAlign = TextAlign.Center,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                        modifier = Modifier.testTag("nav_error"),
                    )
                    Button(
                        onClick = { scope.launch { store.load() } },
                        modifier = Modifier.testTag("nav_retry"),
                    ) { Text("Try Again") }
                }
            }
        is NavigationStore.Phase.Loaded ->
            ShellContent(phase.navigation, session, user, web)
    }
}

/** The tab bar holds at most this many server entries; the More tab is always
 *  the last slot. */
private const val MAX_TABS = 4

private sealed class MoreDest {
    data class Entry(val entry: NavEntry) : MoreDest()
    object Settings : MoreDest()
}

@Composable
private fun ShellContent(
    navigation: AppNavigation,
    session: SessionController,
    user: UserSummary,
    web: WebSessionCoordinator,
) {
    val pinned = navigation.tabEntries.take(MAX_TABS)
    // Overflow pinned entries and everything unpinned live in More.
    val overflow = navigation.tabEntries.drop(MAX_TABS)
    val more = overflow + navigation.moreEntries
    val moreIndex = pinned.size

    var selectedRaw by rememberSaveable { mutableStateOf(0) }
    val selected = selectedRaw.coerceIn(0, moreIndex)
    val moreStack = remember { mutableStateListOf<MoreDest>() }

    // On the More tab, system back pops the pushed detail before leaving the app.
    BackHandler(enabled = selected == moreIndex && moreStack.isNotEmpty()) {
        moreStack.removeAt(moreStack.lastIndex)
    }

    Scaffold(bottomBar = {
        NavigationBar {
            pinned.forEachIndexed { index, entry ->
                NavigationBarItem(
                    selected = selected == index,
                    onClick = { selectedRaw = index },
                    icon = { Icon(entry.iconVector, contentDescription = null) },
                    label = { Text(entry.title, maxLines = 1) },
                    modifier = Modifier.testTag("nav_tab_${entry.slug}"),
                )
            }
            NavigationBarItem(
                selected = selected == moreIndex,
                onClick = { selectedRaw = moreIndex },
                icon = { Icon(Icons.Filled.MoreHoriz, contentDescription = null) },
                label = { Text("More", maxLines = 1) },
                modifier = Modifier.testTag("nav_tab_more"),
            )
        }
    }) { padding ->
        Box(Modifier.fillMaxSize().padding(padding)) {
            if (selected < moreIndex) {
                DestinationScreen(pinned[selected], session, user, web, onBack = null)
            } else if (moreStack.isEmpty()) {
                MoreList(
                    more,
                    onEntry = { moreStack.add(MoreDest.Entry(it)) },
                    onSettings = { moreStack.add(MoreDest.Settings) },
                )
            } else {
                val pop = { moreStack.removeAt(moreStack.lastIndex); Unit }
                when (val dest = moreStack.last()) {
                    is MoreDest.Entry -> DestinationScreen(dest.entry, session, user, web, onBack = pop)
                    MoreDest.Settings -> SettingsScreen(session, user, web, onExit = pop)
                }
            }
        }
    }
}

/**
 * Version-safe destination resolution: web renders in the webview; native
 * renders the named screen when this build knows it — joinery-android's own
 * screens first ("settings"), then the app-registered [NativeScreenRegistry] —
 * else its fallback URL (spec § Navigation endpoint).
 */
@Composable
private fun DestinationScreen(
    entry: NavEntry,
    session: SessionController,
    user: UserSummary,
    web: WebSessionCoordinator,
    onBack: (() -> Unit)?,
) {
    when (val dest = entry.destination) {
        is NavDestination.Web -> WebContent(entry.title, dest.url, session.client, web, onBack)
        is NavDestination.Native -> when (dest.screen) {
            "settings" -> SettingsScreen(session, user, web, onExit = onBack)
            else -> {
                val context = NativeScreenContext(session, user, web, onExit = onBack ?: {})
                when {
                    NativeScreenRegistry.contains(dest.screen) ->
                        NativeScreenRegistry.Render(dest.screen, context)
                    dest.fallbackUrl.isEmpty() ->
                        UpdateRequiredNotice(entry.title, onBack)
                    else ->
                        WebContent(entry.title, dest.fallbackUrl, session.client, web, onBack)
                }
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun WebContent(
    title: String,
    target: String,
    client: ApiClient,
    web: WebSessionCoordinator,
    onBack: (() -> Unit)?,
) {
    val state = remember(target) { WebPageState() }
    Scaffold(topBar = {
        TopAppBar(
            title = { Text(state.pageTitle.ifEmpty { title }, maxLines = 1) },
            navigationIcon = { BackButton(onBack) },
        )
    }) { padding ->
        WebScreen(target, client, web, state, Modifier.padding(padding))
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun UpdateRequiredNotice(title: String, onBack: (() -> Unit)?) {
    Scaffold(topBar = {
        TopAppBar(title = { Text(title, maxLines = 1) }, navigationIcon = { BackButton(onBack) })
    }) { padding ->
        Box(Modifier.fillMaxSize().padding(padding).padding(24.dp), contentAlignment = Alignment.Center) {
            Text(
                "Update the app to use $title.",
                textAlign = TextAlign.Center,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun MoreList(
    entries: List<NavEntry>,
    onEntry: (NavEntry) -> Unit,
    onSettings: () -> Unit,
) {
    Scaffold(topBar = { TopAppBar(title = { Text("More") }) }) { padding ->
        Column(
            Modifier.fillMaxSize().padding(padding).verticalScroll(rememberScrollState()),
        ) {
            for (entry in entries) {
                MoreRow(entry.title, entry.iconVector, "more_${entry.slug}") { onEntry(entry) }
            }
            HorizontalDivider()
            MoreRow("Settings", Icons.Filled.Settings, "more_settings", onSettings)
        }
    }
}

@Composable
private fun MoreRow(
    label: String,
    icon: androidx.compose.ui.graphics.vector.ImageVector,
    tag: String,
    onClick: () -> Unit,
) {
    Row(
        Modifier.fillMaxWidth().clickable(onClick = onClick).padding(16.dp).testTag(tag),
        horizontalArrangement = Arrangement.spacedBy(16.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(icon, contentDescription = null, tint = MaterialTheme.colorScheme.primary)
        Text(label)
    }
}

@Composable
private fun BackButton(onBack: (() -> Unit)?) {
    if (onBack != null) {
        IconButton(onClick = onBack) {
            Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "Back")
        }
    }
}
