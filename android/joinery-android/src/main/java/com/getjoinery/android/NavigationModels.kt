package com.getjoinery.android

import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.HelpOutline
import androidx.compose.material.icons.filled.AccountCircle
import androidx.compose.material.icons.filled.Autorenew
import androidx.compose.material.icons.filled.Build
import androidx.compose.material.icons.filled.CalendarMonth
import androidx.compose.material.icons.filled.Dashboard
import androidx.compose.material.icons.filled.Devices
import androidx.compose.material.icons.filled.Email
import androidx.compose.material.icons.filled.Handyman
import androidx.compose.material.icons.filled.Home
import androidx.compose.material.icons.filled.Key
import androidx.compose.material.icons.filled.PersonAdd
import androidx.compose.material.icons.filled.Schedule
import androidx.compose.material.icons.filled.Search
import androidx.compose.material.icons.filled.Shield
import androidx.compose.material.icons.filled.ShoppingBag
import androidx.compose.material.icons.filled.SmartToy
import androidx.compose.material.icons.filled.Widgets
import androidx.compose.ui.graphics.vector.ImageVector

/**
 * Where a navigation entry goes when tapped. Parsed version-safely: a `native`
 * destination whose `screen` this build does not recognize resolves to its
 * `fallback_url` at render time, and a destination `type` this build has never
 * heard of falls back to any URL the server supplied — promoting a surface
 * server-side never breaks a shipped client.
 */
sealed class NavDestination {
    /** Render `url` in the authenticated webview. */
    data class Web(val url: String) : NavDestination()

    /** Render the named native screen if this build recognizes it, else load
     *  `fallbackUrl` in the webview. */
    data class Native(val screen: String, val fallbackUrl: String) : NavDestination()

    companion object {
        fun from(json: JsonValue?): NavDestination? {
            if (json == null) return null
            val type = json["type"]?.stringValue ?: ""
            val url = json["url"]?.stringValue ?: ""
            val fallback = json["fallback_url"]?.stringValue ?: ""
            return when (type) {
                "web" -> if (url.isNotEmpty()) Web(url) else null
                "native" -> {
                    val screen = json["screen"]?.stringValue
                    if (screen.isNullOrEmpty()) null else Native(screen, fallback)
                }
                else ->
                    // Future destination type: use whatever URL came with it.
                    when {
                        url.isNotEmpty() -> Web(url)
                        fallback.isNotEmpty() -> Web(fallback)
                        else -> null
                    }
            }
        }
    }
}

/** One entry from `GET /api/v1/app/navigation`. */
data class NavEntry(
    val slug: String,
    val title: String,
    val icon: String,
    val order: Int,
    val destination: NavDestination,
) {
    /** Material icon for the server's icon vocabulary (the same names the web
     *  menu store uses). Unknown names get a neutral placeholder. */
    val iconVector: ImageVector
        get() = when (icon) {
            "home" -> Icons.Filled.Home
            "user" -> Icons.Filled.AccountCircle
            "user-plus" -> Icons.Filled.PersonAdd
            "calendar" -> Icons.Filled.CalendarMonth
            "envelope" -> Icons.Filled.Email
            "shopping-bag" -> Icons.Filled.ShoppingBag
            "refresh" -> Icons.Filled.Autorenew
            "robot" -> Icons.Filled.SmartToy
            "shield" -> Icons.Filled.Shield
            "devices" -> Icons.Filled.Devices
            "clock" -> Icons.Filled.Schedule
            "key" -> Icons.Filled.Key
            "search" -> Icons.Filled.Search
            "wrench" -> Icons.Filled.Build
            "tools" -> Icons.Filled.Handyman
            "dashboard" -> Icons.Filled.Dashboard
            "question-circle" -> Icons.AutoMirrored.Filled.HelpOutline
            else -> Icons.Filled.Widgets
        }

    companion object {
        fun from(json: JsonValue): NavEntry? {
            val slug = json["slug"]?.stringValue
            if (slug.isNullOrEmpty()) return null
            val title = json["title"]?.stringValue ?: return null
            val destination = NavDestination.from(json["destination"]) ?: return null
            return NavEntry(
                slug = slug,
                title = title,
                icon = json["icon"]?.stringValue ?: "",
                order = json["order"]?.intValue ?: 0,
                destination = destination,
            )
        }
    }
}

/**
 * The parsed navigation response: every entry the user received (server order
 * preserved) plus the slugs pinned to this app's tab bar.
 */
data class AppNavigation(
    val entries: List<NavEntry>,
    val tabSlugs: List<String>,
) {
    /** Entries pinned to the tab bar, in the server's pinning order. */
    val tabEntries: List<NavEntry>
        get() = tabSlugs.mapNotNull { slug -> entries.firstOrNull { it.slug == slug } }

    /** Everything else, in server order — the More list. */
    val moreEntries: List<NavEntry>
        get() = entries.filter { it.slug !in tabSlugs }

    companion object {
        fun from(data: JsonValue?): AppNavigation? {
            if (data == null) return null
            val entries = (data["entries"]?.arrayValue ?: emptyList()).mapNotNull { NavEntry.from(it) }
            if (entries.isEmpty()) return null
            val tabs = (data["tabs"]?.arrayValue ?: emptyList()).mapNotNull { it.stringValue }
            return AppNavigation(entries, tabs)
        }
    }
}
