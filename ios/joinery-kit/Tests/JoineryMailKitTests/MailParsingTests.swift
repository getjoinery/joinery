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
        // With no display name the sending organization is the label, not the local part.
        XCTAssertEqual(MailDisplay.senderName("jane@example.com"), "Example")
        XCTAssertEqual(MailDisplay.address("Jane Doe <jane@example.com>"), "jane@example.com")
        XCTAssertEqual(MailDisplay.address("  jane@example.com "), "jane@example.com")
        // Stable avatar bucket, in range.
        let a = MailDisplay.avatarColorIndex("Jane <jane@example.com>", paletteSize: 8)
        let b = MailDisplay.avatarColorIndex("jane@example.com", paletteSize: 8)
        XCTAssertEqual(a, b, "same address hashes to the same bucket regardless of display name")
        XCTAssertTrue((0..<8).contains(a))
    }

    /// The label rules, mirroring the web reader's sender_name.mjs gate: the same
    /// message must read the same way in the app and in the browser.
    func testSenderLabelRules() {
        // A display name always wins, verbatim.
        XCTAssertEqual(MailDisplay.senderName("\"Fireworks Team\" <hello@fireworks.ai>"), "Fireworks Team")
        XCTAssertEqual(MailDisplay.senderName("\"iA Writer\" <news@ia.net>"), "iA Writer")

        // No name: the organization, not the local part.
        XCTAssertEqual(MailDisplay.senderName("hello@fireworks.ai"), "Fireworks")
        XCTAssertEqual(MailDisplay.senderName("no-reply@accounts.google.com"), "Google")
        XCTAssertEqual(MailDisplay.senderName("alerts@e-trade.com"), "E-Trade")
        XCTAssertEqual(MailDisplay.senderName("noreply@mail.notifications.example.co.uk"), "Example")
        XCTAssertEqual(MailDisplay.senderName("info@bundesbank.de"), "Bundesbank")

        // Consumer providers: the person is the identity.
        XCTAssertEqual(MailDisplay.senderName("jeremy.tunnell@gmail.com"), "Jeremy Tunnell")
        XCTAssertEqual(MailDisplay.senderName("a.b.cooper@icloud.com"), "A B Cooper")
        XCTAssertEqual(MailDisplay.senderName("someone@proton.me"), "Someone")

        // ...but only for what could be a person's mailbox.
        XCTAssertEqual(MailDisplay.senderName("no-reply@notify.proton.me"), "Proton")
        XCTAssertEqual(MailDisplay.senderName("support@proton.me"), "Proton")
        XCTAssertEqual(MailDisplay.senderName("AmericanExpress-no-reply@alerts.americanexpress.com"), "Americanexpress")
        XCTAssertEqual(MailDisplay.senderName("info.smith@gmail.com"), "Info Smith")

        // Degenerate input never crashes or shows an empty label.
        XCTAssertEqual(MailDisplay.senderName(""), "(unknown)")
        XCTAssertEqual(MailDisplay.senderName("garbage"), "garbage")
        XCTAssertEqual(MailDisplay.senderName("@gmail.com"), "@gmail.com")

        // The host reducer.
        XCTAssertEqual(MailDisplay.orgLabel("accounts.google.com"), "google")
        XCTAssertEqual(MailDisplay.orgLabel("example.co.uk"), "example")
        XCTAssertEqual(MailDisplay.orgLabel("localhost"), "localhost")
        XCTAssertTrue(MailDisplay.hasSubdomain("notify.proton.me"))
        XCTAssertFalse(MailDisplay.hasSubdomain("proton.me"))
        XCTAssertFalse(MailDisplay.hasSubdomain("example.co.uk"))
        XCTAssertTrue(MailDisplay.hasSubdomain("mail.example.co.uk"))
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
