import XCTest

/// Gate: a mailbox-granted user reads and replies to mail in-app — on the
/// NATIVE mail screens (JoineryMailKit; the Email tab's navigation entry is
/// `{type: "native", screen: "mailbox"}`). The runner grants the fixture
/// user a mailbox and seeds a message before this suite, passing its
/// subject/body via the environment; the reply's server-side arrival is
/// verified by the runner afterwards.
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

        // Native proof: the thread list renders (no webview involved).
        app.expect(app.collectionViews["mail_list"].firstMatch, timeout: 20, "native mail list")
        XCTAssertFalse(app.webViews.firstMatch.exists, "the Email tab must be native, not a webview")

        // The seeded message is in the list; open its thread.
        let listRow = app.staticTexts[subject].firstMatch
        app.expect(listRow, timeout: 20, "seeded message in native list")
        listRow.tap()

        // Thread open: subject header + the message body render natively.
        app.expect(app.staticTexts["mail_thread_subject"].firstMatch, timeout: 15, "thread subject header")
        let bodyText = app.staticTexts
            .matching(NSPredicate(format: "label CONTAINS %@", bodySnippet)).firstMatch
        app.expect(bodyText, timeout: 10, "message body in thread")

        // Reply: the compose sheet opens prefilled; type and send.
        app.buttons["mail_reply"].tap()
        let body = app.textViews["mail_compose_body"].firstMatch
        app.expect(body, timeout: 10, "compose body editor")
        body.tap()
        body.typeText(replyText)

        let send = app.buttons["mail_compose_send"].firstMatch
        app.expect(send, timeout: 5, "send button")
        send.tap()

        // Success dismisses the sheet (a failed send keeps it open with an
        // inline error) — wait for Send to disappear before trusting any
        // text match, so the draft in the editor can't fake a pass.
        let composeClosed = XCTNSPredicateExpectation(
            predicate: NSPredicate(format: "exists == false"), object: send)
        XCTAssertEqual(XCTWaiter.wait(for: [composeClosed], timeout: 30), .completed,
                       "compose should dismiss after a successful send")

        // The thread reloads with the outbound reply.
        let sentReply = app.staticTexts
            .matching(NSPredicate(format: "label CONTAINS %@", replyText)).firstMatch
        app.expect(sentReply, timeout: 25, "sent reply in thread")
    }
}
