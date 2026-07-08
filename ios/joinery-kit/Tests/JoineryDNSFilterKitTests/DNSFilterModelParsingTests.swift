import XCTest
@testable import JoineryDNSFilterKit
import JoineryKit

/// Parsing tests against the exact JSON shapes ScrollDaddyHelper emits
/// (exportDevice / exportBlock, account_summary, catalog). Guards the client
/// against silent drift if a server payload key changes.
final class DNSFilterModelParsingTests: XCTestCase {

    private func json(_ text: String) -> JSONValue {
        try! JSONValue.parse(text)
    }

    func testDeviceParsing() {
        let data = json("""
        {"device_id":42,"name":"My iPhone","device_name":"My iPhone","device_type":"phone",
         "timezone":"America/New_York","is_active":true,"log_queries":false,"filters_editable":true,
         "resolver_uid":"abc123","doh_url":"https://dns.scrolldaddy.app/resolve/abc123",
         "dot_hostname":"abc123.dns.scrolldaddy.app","hard_block_hostnames":["porn.example","bet.example"],
         "last_seen":"2026-07-08 12:00:00",
         "blocks":[{"block_id":7,"name":"Always-On Rules","is_always_on":true,"is_active":true,
                    "active_now":true,"rule_count":3,"schedule":{"start":null,"end":null,"days":[],"timezone":null}},
                   {"block_id":8,"name":"Bedtime","is_always_on":false,"is_active":true,"active_now":false,
                    "rule_count":1,"schedule":{"start":"22:00","end":"06:00","days":[1,2,3],"timezone":"America/New_York"}}]}
        """)
        let device = DNSDevice(json: data)
        XCTAssertNotNil(device)
        XCTAssertEqual(device?.deviceID, 42)
        XCTAssertEqual(device?.dohURL, "https://dns.scrolldaddy.app/resolve/abc123")
        XCTAssertEqual(device?.hardBlockHostnames, ["porn.example", "bet.example"])
        XCTAssertEqual(device?.alwaysOnBlock?.blockID, 7)
        XCTAssertEqual(device?.scheduledBlocks.count, 1)
        XCTAssertEqual(device?.scheduledBlocks.first?.schedule.days, [1, 2, 3])
        // The fixture's last_seen is the bare-string form.
        XCTAssertEqual(device?.lastSeen, "2026-07-08 12:00:00")
    }

    func testDeviceLastSeenObjectAndNull() {
        // The live devices action proxies last_seen from the DNS server as an
        // object ({seen: ...}) or null; both must fold correctly.
        func device(_ lastSeen: String) -> DNSDevice? {
            DNSDevice(json: json("""
            {"device_id":1,"name":"d","device_name":"d","device_type":"phone",
             "timezone":"UTC","is_active":true,"log_queries":false,"filters_editable":true,
             "resolver_uid":"u","doh_url":"https://x/resolve/u","dot_hostname":"u.x",
             "hard_block_hostnames":[],"last_seen":\(lastSeen),"blocks":[]}
            """))
        }
        XCTAssertEqual(device("{\"seen\":\"2026-07-08 12:00:00\"}")?.lastSeen, "2026-07-08 12:00:00")
        XCTAssertNil(device("null")?.lastSeen)
    }

    func testBlockContentsFiltersAsMap() {
        // get_filter_rules() / get_service_rules() emit {key: action_int} maps.
        let data = json("""
        {"device_id":42,"block":{"block_id":7,"name":"Always-On Rules","is_always_on":true,
          "is_active":true,"active_now":true,"schedule":{"start":null,"end":null,"days":[],"timezone":null},
          "filters":{"gambling":0,"adult":0,"safesearch":1},
          "services":{"reddit":0},
          "rules":[{"rule_id":5,"hostname":"youtube.com","action":1,"is_active":true,"hard_block":false},
                   {"rule_id":6,"hostname":"casino.example","action":0,"is_active":true,"hard_block":true}]}}
        """)
        let contents = DNSBlockContents(data: data)
        XCTAssertNotNil(contents)
        XCTAssertEqual(contents?.blockID, 7)
        XCTAssertTrue(contents?.isAlwaysOn ?? false)
        // Only action==0 rows are "blocked".
        XCTAssertEqual(contents?.filters["gambling"], 0)
        XCTAssertEqual(contents?.filters["safesearch"], 1)
        XCTAssertEqual(contents?.services["reddit"], 0)
        XCTAssertEqual(contents?.rules.count, 2)
        XCTAssertEqual(contents?.rules.first(where: { $0.ruleID == 6 })?.hardBlock, true)
    }

    func testBlockContentsEmptyFiltersAsArray() {
        // An empty PHP assoc array serializes as [], not {} — must still parse.
        let data = json("""
        {"device_id":42,"block":{"block_id":9,"name":"Always-On Rules","is_always_on":true,
          "is_active":true,"active_now":true,"schedule":{"start":null,"end":null,"days":[],"timezone":null},
          "filters":[],"services":[],"rules":[]}}
        """)
        let contents = DNSBlockContents(data: data)
        XCTAssertNotNil(contents)
        XCTAssertTrue(contents?.filters.isEmpty ?? false)
        XCTAssertTrue(contents?.rules.isEmpty ?? false)
    }

    func testAccountSummaryFlags() {
        let data = json("""
        {"tier_name":"Premium","features":{"scrolldaddy_max_devices":3,"scrolldaddy_max_scheduled_blocks":2,
          "scrolldaddy_custom_rules":true,"scrolldaddy_advanced_filters":true,"scrolldaddy_query_logging":false},
         "device_count":2,"device_max":3}
        """)
        let account = DNSAccountSummary(data: data)
        XCTAssertEqual(account?.tierName, "Premium")
        XCTAssertEqual(account?.maxDevices, 3)
        XCTAssertTrue(account?.customRules ?? false)
        XCTAssertTrue(account?.advancedFilters ?? false)
        XCTAssertFalse(account?.atDeviceLimit ?? true)
    }

    func testAccountSummaryFreeTierAtLimit() {
        let data = json("""
        {"tier_name":"Basic","features":{"scrolldaddy_max_devices":1,"scrolldaddy_max_scheduled_blocks":0,
          "scrolldaddy_custom_rules":false,"scrolldaddy_advanced_filters":false,"scrolldaddy_query_logging":false},
         "device_count":1,"device_max":1}
        """)
        let account = DNSAccountSummary(data: data)
        XCTAssertFalse(account?.customRules ?? true)
        XCTAssertTrue(account?.atDeviceLimit ?? false)
    }

    func testCatalogParsingAndAdvancedSplit() {
        let data = json("""
        {"filters":[{"key":"adult","label":"Adult Content","advanced":false},
                    {"key":"gambling","label":"Gambling","advanced":false},
                    {"key":"malware","label":"Malware","advanced":true}],
         "service_categories":[{"key":"social","label":"Social Media"}],
         "services":{"social":[{"key":"reddit","label":"Reddit"},{"key":"tiktok","label":"TikTok"}]}}
        """)
        let catalog = DNSCatalog(data: data)
        XCTAssertNotNil(catalog)
        XCTAssertEqual(catalog?.generalFilters.map(\.key), ["adult", "gambling"])
        XCTAssertEqual(catalog?.advancedFilters.map(\.key), ["malware"])
        XCTAssertEqual(catalog?.services["social"]?.count, 2)
    }
}
