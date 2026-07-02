import XCTest
@testable import JoineryKit

/// Navigation response parsing and the tab/More partition — against the
/// live-captured navigation.json fixture plus constructed destination cases.
final class NavigationParsingTests: XCTestCase {

    private func liveNavigation() throws -> AppNavigation {
        let envelope = try JSONValue.parse(try fixture("navigation.json"))
        let navigation = AppNavigation(data: envelope["data"])
        return try XCTUnwrap(navigation)
    }

    func testParsesLiveFixture() throws {
        let navigation = try liveNavigation()
        XCTAssertEqual(navigation.entries.count, 11)
        XCTAssertEqual(navigation.tabSlugs, ["core-profile", "core-calendar", "inbound-email-mailbox"])

        let calendar = try XCTUnwrap(navigation.entries.first { $0.slug == "core-calendar" })
        XCTAssertEqual(calendar.title, "Calendar")
        XCTAssertEqual(calendar.icon, "calendar")
        XCTAssertEqual(calendar.order, 55)
        XCTAssertEqual(calendar.destination, .web(url: "/profile/calendar"))
    }

    func testTabAndMorePartitionPreservesOrder() throws {
        let navigation = try liveNavigation()

        // Tabs come back in pinning order, not entry order.
        XCTAssertEqual(navigation.tabEntries.map(\.slug),
                       ["core-profile", "core-calendar", "inbound-email-mailbox"])

        // More is everything else, in server entry order.
        XCTAssertEqual(navigation.moreEntries.first?.slug, "core-home")
        XCTAssertEqual(navigation.moreEntries.count, navigation.entries.count - 3)
        XCTAssertFalse(navigation.moreEntries.contains { navigation.tabSlugs.contains($0.slug) })
    }

    func testPinnedSlugTheUserDidNotReceiveIsDropped() throws {
        let json = try JSONValue.parse("""
        {"tabs": ["missing-slug", "a"], "entries": [
            {"slug": "a", "title": "A", "icon": "", "order": 1,
             "destination": {"type": "web", "url": "/a"}}
        ]}
        """.data(using: .utf8)!)
        let navigation = try XCTUnwrap(AppNavigation(data: json))
        XCTAssertEqual(navigation.tabEntries.map(\.slug), ["a"])
    }

    func testNativeDestinationParses() throws {
        let json = try JSONValue.parse("""
        {"type": "native", "screen": "settings", "fallback_url": "/profile/settings"}
        """.data(using: .utf8)!)
        XCTAssertEqual(NavDestination(json: json),
                       .native(screen: "settings", fallbackURL: "/profile/settings"))
    }

    func testUnknownDestinationTypeFallsBackToItsURL() throws {
        // A future server destination type must not break this build: any
        // URL it carries renders as web.
        let withURL = try JSONValue.parse(
            """
            {"type": "hologram", "url": "/profile/hologram"}
            """.data(using: .utf8)!)
        XCTAssertEqual(NavDestination(json: withURL), .web(url: "/profile/hologram"))

        let withFallback = try JSONValue.parse(
            """
            {"type": "hologram", "fallback_url": "/profile/fallback"}
            """.data(using: .utf8)!)
        XCTAssertEqual(NavDestination(json: withFallback), .web(url: "/profile/fallback"))

        let withNothing = try JSONValue.parse("{\"type\": \"hologram\"}".data(using: .utf8)!)
        XCTAssertNil(NavDestination(json: withNothing))
    }

    func testEntryWithoutSlugOrDestinationIsDropped() throws {
        let json = try JSONValue.parse("""
        {"tabs": [], "entries": [
            {"slug": "good", "title": "Good", "order": 1,
             "destination": {"type": "web", "url": "/good"}},
            {"title": "No slug", "order": 2,
             "destination": {"type": "web", "url": "/x"}},
            {"slug": "no-destination", "title": "X", "order": 3}
        ]}
        """.data(using: .utf8)!)
        let navigation = try XCTUnwrap(AppNavigation(data: json))
        XCTAssertEqual(navigation.entries.map(\.slug), ["good"])
    }

    func testIconMappingCoversServerVocabularyWithFallback(){
        let known = NavEntry.testEntry(icon: "calendar")
        XCTAssertEqual(known.systemImage, "calendar")
        let unknown = NavEntry.testEntry(icon: "never-heard-of-it")
        XCTAssertEqual(unknown.systemImage, "square.grid.2x2")
    }
}

private extension NavEntry {
    static func testEntry(icon: String) -> NavEntry {
        NavEntry(json: .object([
            (key: "slug", value: .string("t")),
            (key: "title", value: .string("T")),
            (key: "icon", value: .string(icon)),
            (key: "order", value: .number(1)),
            (key: "destination", value: .object([
                (key: "type", value: .string("web")),
                (key: "url", value: .string("/t")),
            ])),
        ]))!
    }
}
