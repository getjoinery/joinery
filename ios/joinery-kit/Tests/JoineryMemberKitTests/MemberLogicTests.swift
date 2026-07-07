import XCTest
@testable import JoineryMemberKit
@testable import JoineryKit

/// Pure logic tests: status filtering and the conversation thread's cursor
/// math — no network, no fixtures.
final class MemberLogicTests: XCTestCase {

    // MARK: EventStatusFilter

    func testEventStatusFilterCasesMatchServerVocabulary() {
        // logic/my_events_logic.php derives exactly these five status strings;
        // the filter's raw values must round-trip through the `status` param.
        let expected: Set<String> = ["all", "active", "expired", "canceled", "completed"]
        XCTAssertEqual(Set(EventStatusFilter.allCases.map(\.rawValue)), expected)
    }

    func testEventStatusFilterTitlesAreHumanReadable() {
        for status in EventStatusFilter.allCases {
            XCTAssertFalse(status.title.isEmpty)
        }
    }

    // MARK: ThreadCursorMath

    func testNextAfterCursorUsesLastMessageTime() {
        let messages = [
            ThreadMessage(messageID: 1, senderID: 1, body: "hi", time: "2026-07-01 10:00:00", isMine: true),
            ThreadMessage(messageID: 2, senderID: 2, body: "hey", time: "2026-07-01 10:05:00", isMine: false),
        ]
        let cursor = ThreadCursorMath.nextAfterCursor(messages: messages, hasMore: true)
        XCTAssertEqual(cursor, "2026-07-01 10:05:00")
    }

    func testNextAfterCursorNilWhenNoMore() {
        let messages = [
            ThreadMessage(messageID: 1, senderID: 1, body: "hi", time: "2026-07-01 10:00:00", isMine: true),
        ]
        XCTAssertNil(ThreadCursorMath.nextAfterCursor(messages: messages, hasMore: false))
    }

    func testNextAfterCursorNilWhenEmpty() {
        XCTAssertNil(ThreadCursorMath.nextAfterCursor(messages: [], hasMore: true))
    }

    // MARK: MemberDisplay

    func testDateLabelHandlesMissingTime() {
        XCTAssertEqual(MemberDisplay.dateLabel(nil), "")
        XCTAssertEqual(MemberDisplay.dateLabel(""), "")
    }

    func testDateParsesTruncatedMicroseconds() {
        // security_overview's created_time carries fractional seconds
        // ("2026-07-07 15:50:57.791146"); the display helpers must still parse.
        let date = MemberDisplay.date("2026-07-07 15:50:57.791146")
        XCTAssertNotNil(date)
    }
}
