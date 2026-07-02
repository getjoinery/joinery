import XCTest

/// Gate: a mailbox-granted user reads and replies to mail in-app. The runner
/// grants the fixture user a mailbox and seeds a message before this suite,
/// passing its subject/body via the environment; the reply's server-side
/// arrival is verified by the runner afterwards.
final class MailboxUITests: XCTestCase {

    override func setUp() {
        continueAfterFailure = false
    }

    func testReadAndReplyToMail() throws {
        let subject = TestEnv.require("JOINERY_MAIL_SUBJECT")
        let bodySnippet = TestEnv.require("JOINERY_MAIL_BODY_SNIPPET")
        let replyText = TestEnv.require("JOINERY_MAIL_REPLY_TEXT")

        let app = XCUIApplication()
        app.launchJoinery()
        app.signIn(email: TestEnv.email, password: TestEnv.password)
        app.expectSignedIn()
        app.tabBars.buttons["Email"].tap()

        // The seeded message is in the list; open it.
        let webView = app.webViews.firstMatch
        let listRow = webView.staticTexts[subject].firstMatch
        app.expect(listRow, timeout: 30, "seeded message in mailbox list")
        // Accepting the consent banner can re-render the page — wait for
        // the list to settle again before tapping.
        app.dismissCookieConsent()
        app.expect(listRow, timeout: 15, "message list after consent dismissal")
        listRow.tap()

        // Thread open: the reply chips render under the message.
        let replyChip = webView.buttons["↩ Reply"].firstMatch
        app.expect(replyChip, timeout: 20, "reply chip (thread open)")

        // Read: the plain-text body renders in the thread. CONTAINS — the
        // <pre> label keeps the message's trailing newline.
        let bodyText = webView.staticTexts
            .matching(NSPredicate(format: "label CONTAINS %@", bodySnippet)).firstMatch
        app.expect(bodyText, timeout: 10, "message body in thread")

        // Reply: compose opens prefilled; type and send.
        replyChip.tap()

        let body = webView.textViews.firstMatch
        app.expect(body, timeout: 10, "compose body field")
        body.tap()
        body.typeText(replyText)

        // Dismiss the keyboard first — while it is up the page is scrolled
        // and Send's reported frame is stale, so taps miss. The accessory
        // Done button surfaces as a toolbar button or a plain button.
        let toolbarDone = app.toolbars.buttons["Done"]
        if toolbarDone.waitForExistence(timeout: 3) {
            toolbarDone.tap()
        } else if app.buttons["Done"].exists {
            app.buttons["Done"].tap()
        }

        let send = webView.buttons["Send"].firstMatch
        app.expect(send, timeout: 10, "send button")
        if send.isHittable {
            send.tap()
        } else {
            send.coordinate(withNormalizedOffset: CGVector(dx: 0.5, dy: 0.5)).tap()
        }

        // Success closes the compose box (a failed send keeps it open with
        // an inline error) — wait for Send to disappear before trusting any
        // text match, so the draft in the textarea can't fake a pass.
        let composeClosed = XCTNSPredicateExpectation(
            predicate: NSPredicate(format: "exists == false"), object: send)
        XCTAssertEqual(XCTWaiter.wait(for: [composeClosed], timeout: 20), .completed,
                       "compose should close after a successful send")

        // The thread re-renders with the outbound reply.
        let sentReply = webView.staticTexts
            .matching(NSPredicate(format: "label CONTAINS %@", replyText)).firstMatch
        app.expect(sentReply, timeout: 25, "sent reply in thread")
    }
}
