import Foundation

/// Where a navigation entry goes when tapped. Parsed version-safely: a
/// `native` destination whose `screen` this build does not recognize is
/// resolved to its `fallback_url` at render time, and a destination `type`
/// this build has never heard of falls back to any URL the server supplied —
/// promoting a surface server-side never breaks a shipped client.
public enum NavDestination: Equatable, Sendable {
    /// Render `url` in the authenticated webview.
    case web(url: String)
    /// Render the named native screen if this build recognizes it, else
    /// load `fallbackURL` in the webview.
    case native(screen: String, fallbackURL: String)

    init?(json: JSONValue?) {
        guard let json else { return nil }
        let type = json["type"]?.stringValue ?? ""
        let url = json["url"]?.stringValue ?? ""
        let fallback = json["fallback_url"]?.stringValue ?? ""
        switch type {
        case "web" where !url.isEmpty:
            self = .web(url: url)
        case "native":
            guard let screen = json["screen"]?.stringValue, !screen.isEmpty else { return nil }
            self = .native(screen: screen, fallbackURL: fallback)
        default:
            // Future destination type: use whatever URL came with it.
            if !url.isEmpty { self = .web(url: url) }
            else if !fallback.isEmpty { self = .web(url: fallback) }
            else { return nil }
        }
    }
}

/// One entry from `GET /api/v1/app/navigation`.
public struct NavEntry: Identifiable, Equatable, Sendable {
    public let slug: String
    public let title: String
    public let icon: String
    public let order: Int
    public let destination: NavDestination

    public var id: String { slug }

    init?(json: JSONValue) {
        guard let slug = json["slug"]?.stringValue, !slug.isEmpty,
              let title = json["title"]?.stringValue,
              let destination = NavDestination(json: json["destination"])
        else { return nil }
        self.slug = slug
        self.title = title
        self.icon = json["icon"]?.stringValue ?? ""
        self.order = json["order"]?.intValue ?? 0
        self.destination = destination
    }

    /// SF Symbol for the server's icon vocabulary (the same names the web
    /// menu store uses). Unknown names get a neutral placeholder.
    public var systemImage: String {
        switch icon {
        case "home": return "house"
        case "user": return "person.crop.circle"
        case "user-plus": return "person.badge.plus"
        case "calendar": return "calendar"
        case "envelope": return "envelope"
        case "shopping-bag": return "bag"
        case "refresh": return "arrow.triangle.2.circlepath"
        case "robot": return "sparkles"
        case "shield": return "shield"
        case "devices": return "laptopcomputer.and.iphone"
        case "clock": return "clock"
        case "key": return "key"
        case "search": return "magnifyingglass"
        case "wrench": return "wrench.adjustable"
        case "tools": return "wrench.and.screwdriver"
        case "dashboard": return "gauge.with.needle"
        case "question-circle": return "questionmark.circle"
        default: return "square.grid.2x2"
        }
    }
}

/// The parsed navigation response: every entry the user received (server
/// order preserved) plus the slugs pinned to this app's tab bar.
public struct AppNavigation: Equatable, Sendable {
    public let entries: [NavEntry]
    public let tabSlugs: [String]

    public init?(data: JSONValue?) {
        guard let data else { return nil }
        let parsed = (data["entries"]?.arrayValue ?? []).compactMap(NavEntry.init(json:))
        guard !parsed.isEmpty else { return nil }
        entries = parsed
        tabSlugs = (data["tabs"]?.arrayValue ?? []).compactMap(\.stringValue)
    }

    /// Entries pinned to the tab bar, in the server's pinning order.
    public var tabEntries: [NavEntry] {
        tabSlugs.compactMap { slug in entries.first { $0.slug == slug } }
    }

    /// Everything else, in server order — the More list.
    public var moreEntries: [NavEntry] {
        entries.filter { !tabSlugs.contains($0.slug) }
    }
}
