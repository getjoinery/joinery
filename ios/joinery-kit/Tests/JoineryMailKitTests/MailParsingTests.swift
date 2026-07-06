import XCTest
@testable import JoineryMailKit
@testable import JoineryKit

/// Parsing tests over live `mailbox/*` payloads captured from dev
/// (Fixtures/*.json are verbatim API envelopes).
final class MailParsingTests: XCTestCase {

    private func fixture(_ name: String) throws -> JSONValue {
        let url = try XCTUnwrap(
            Bundle.module.url(forResource: "Fixtures/\(name).json", withExtension: nil)
                ?? Bundle.module.url(forResource: "\(name).json", withExtension: nil, subdirectory: "Fixtures"),
            "missing fixture \(name).json"
        )
        let envelope = try JSONValue.parse(Data(contentsOf: url))
        return try XCTUnwrap(envelope["data"], "fixture \(name) has no data")
    }

    func testMailboxesFixtureParses() throws {
        let home = try XCTUnwrap(MailboxHome(data: fixture("mailboxes")))
        XCTAssertTrue(home.canCompose)
        XCTAssertEqual(home.mailboxes.count, 1)
        let box = try XCTUnwrap(home.mailboxes.first)
        XCTAssertEqual(box.address, "appdev.phase2@dev.getjoinery.com")
        XCTAssertEqual(box.localPart, "appdev.phase2")
        XCTAssertGreaterThan(box.total, 0)
    }

    func testThreadListFixtureParses() throws {
        let page = try XCTUnwrap(ThreadPage(data: fixture("thread_list")))
        XCTAssertFalse(page.threads.isEmpty)
        XCTAssertEqual(page.page, 1)
        for thread in page.threads {
            XCTAssertFalse(thread.threadKey.isEmpty)
            XCTAssertFalse(thread.latestTime.isEmpty)
        }
        // The seeded probe thread is present with its snippet.
        let probe = page.threads.first { $0.subject.hasPrefix("NativeMail Probe2") }
        XCTAssertNotNil(probe)
        XCTAssertTrue(probe!.snippet.contains("NativeMailProbe2Body"))
    }

    func testThreadFixtureParsesSignedTransport() throws {
        let thread = try XCTUnwrap(MailThread(data: fixture("thread")))
        XCTAssertEqual(thread.messages.count, 1)
        let message = try XCTUnwrap(thread.messages.first)
        XCTAssertEqual(message.direction, "inbound")
        XCTAssertFalse(message.isOutbound)
        XCTAssertEqual(message.bodyPlain, "NativeMailProbe2Body-1783038188")

        // Inline cid: image was rewritten server-side to a signed URL.
        XCTAssertFalse(message.bodyHTML.contains("cid:"))
        XCTAssertTrue(message.bodyHTML.contains("/uploads/"))
        XCTAssertTrue(message.bodyHTML.contains("sig="))

        // The non-inline attachment carries its signed download URL.
        XCTAssertEqual(message.attachments.count, 1)
        let attachment = try XCTUnwrap(message.attachments.first)
        XCTAssertEqual(attachment.filename, "probe.txt")
        XCTAssertNotNil(attachment.url)
        XCTAssertTrue(attachment.url!.contains("sig="))
        XCTAssertEqual(attachment.sizeLabel, "27 B")
    }

    func testAttachmentWithoutFileBackingHasNilURL() throws {
        let json = try JSONValue.parse(#"{"id": 5, "filename": "x.pdf", "content_type": "application/pdf", "size_bytes": 2048, "url": null}"#)
        let attachment = try XCTUnwrap(MailAttachment(json: json))
        XCTAssertNil(attachment.url)
        XCTAssertEqual(attachment.sizeLabel, "2 KB")
    }

    func testSenderDisplayParsing() {
        XCTAssertEqual(MailDisplay.senderName("Jane Doe <jane@example.com>"), "Jane Doe")
        XCTAssertEqual(MailDisplay.senderName("jane@example.com"), "jane")
        XCTAssertEqual(MailDisplay.address("Jane Doe <jane@example.com>"), "jane@example.com")
        XCTAssertEqual(MailDisplay.address("  jane@example.com "), "jane@example.com")
        // Stable avatar bucket, in range.
        let a = MailDisplay.avatarColorIndex("Jane <jane@example.com>", paletteSize: 8)
        let b = MailDisplay.avatarColorIndex("jane@example.com", paletteSize: 8)
        XCTAssertEqual(a, b, "same address hashes to the same bucket regardless of display name")
        XCTAssertTrue((0..<8).contains(a))
    }

    func testDateStamps() {
        let date = MailDisplay.date("2026-07-03 00:23:08")
        XCTAssertNotNil(date)
        // DB times are UTC.
        var cal = Calendar(identifier: .gregorian)
        cal.timeZone = TimeZone(identifier: "UTC")!
        XCTAssertEqual(cal.component(.hour, from: date!), 0)
        XCTAssertFalse(MailDisplay.listStamp("2026-07-03 00:23:08").isEmpty)
        XCTAssertFalse(MailDisplay.messageStamp("2026-07-03 00:23:08").isEmpty)
        XCTAssertEqual(MailDisplay.listStamp("garbage"), "")
    }

    func testThreadRowUnreadDerivation() throws {
        let page = try XCTUnwrap(ThreadPage(data: fixture("thread_list")))
        for thread in page.threads {
            XCTAssertEqual(thread.hasUnread, thread.unreadCount > 0)
        }
    }
}
