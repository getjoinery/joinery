import XCTest

/// Gate: a member reads and replies to a conversation in-app — on the NATIVE
/// conversation screens (JoineryMemberKit; reached from the Profile
/// dashboard's Messages tile, not a menu entry). The runner seeds a
/// conversation for the fixture user before this suite, passing the other
/// participant's display name and the reply text via the environment; the
/// reply's server-side arrival is verified by the runner afterwards.
final class ConversationsUITests: XCTestCase {

    override func setUp() {
        continueAfterFailure = false
    }

    func testNativeConversationRoundTripSend() throws {
        let otherName = TestEnv.require("JOINERY_CONVERSATION_OTHER_NAME")
        let replyText = TestEnv.require("JOINERY_CONVERSATION_REPLY_TEXT")

        let app = XCUIApplication()
        app.launchJoinery()
        app.signIn(email: TestEnv.email, password: TestEnv.password)
        app.expectSignedIn()
        app.tabBars.buttons["My Profile"].tap()
        app.expect(app.staticTexts["profile_user_name"].firstMatch, timeout: 20, "native profile dashboard")

        app.staticTexts["Messages"].firstMatch.tap()

        // Native proof: the inbox list renders (no webview involved).
        app.expect(app.collectionViews["conversations_list"].firstMatch, timeout: 20, "native conversations list")
        XCTAssertFalse(app.webViews.firstMatch.exists, "Conversations must be native, not a webview")

        // Open the seeded conversation.
        let listRow = app.staticTexts[otherName].firstMatch
        app.expect(listRow, timeout: 20, "seeded conversation in native list")
        listRow.tap()

        // Thread open: the compose bar renders natively.
        let composer = app.textFields["conversation_composer"].firstMatch
        app.expect(composer, timeout: 15, "conversation composer")
        XCTAssertFalse(app.webViews.firstMatch.exists, "the conversation thread must be native, not a webview")

        composer.tap()
        composer.typeText(replyText)
        app.buttons["conversation_send"].firstMatch.tap()

        // The sent message renders as an outgoing bubble.
        let sentBubble = app.staticTexts
            .matching(NSPredicate(format: "label CONTAINS %@", replyText)).firstMatch
        app.expect(sentBubble, timeout: 25, "sent reply bubble in thread")
    }
}
