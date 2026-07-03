import XCTest

/// Manual utility, not part of any gate: opens the native AI chat and attaches
/// full-resolution screenshots for visual review. Run explicitly:
///   -only-testing:JoineryMemberUITests/ChatScreenshotUITests
/// then export with `xcrun xcresulttool export attachments`.
///
/// AI Chat is not pinned to the tab bar, so it opens from the More menu
/// (more_joinery-ai-member-chat). Reaching the native ChatScreen here also
/// confirms the server flipped the entry's destination to
/// {type: native, screen: "ai_chat"} and the app resolved it natively rather
/// than falling back to the web chat.
final class ChatScreenshotUITests: XCTestCase {

    override func setUp() {
        continueAfterFailure = true
    }

    func testCaptureChatScreens() throws {
        let app = XCUIApplication()
        app.launchJoinery()
        app.signIn(email: TestEnv.email, password: TestEnv.password)
        app.expectSignedIn()

        // More → AI Chat.
        app.tabBars.buttons["More"].tap()
        let entry = app.buttons["more_joinery-ai-member-chat"]
        XCTAssertTrue(entry.waitForExistence(timeout: 15), "AI Chat entry in More")
        entry.tap()

        // The native list settles to either the conversation list or the empty
        // state — both are native, proving we didn't fall back to the webview.
        let list = app.collectionViews["chat_list"].firstMatch
        let empty = app.otherElements["chat_empty"].firstMatch
        let landed = list.waitForExistence(timeout: 25) || empty.waitForExistence(timeout: 5)
        XCTAssertTrue(landed, "native chat screen did not appear")
        sleep(1)
        attach(app, "1-chat-list")

        // Open the first conversation, if any, and let its turns load + render.
        let firstCell = app.collectionViews["chat_list"].cells.firstMatch
        if firstCell.waitForExistence(timeout: 5) {
            firstCell.tap()
            // Wait for the composer (always present on the thread) and a real
            // message, so we screenshot a settled transcript, not a transition.
            _ = app.textFields["chat_composer"].firstMatch.waitForExistence(timeout: 20)
            let anyMessage = app.descendants(matching: .any)
                .matching(NSPredicate(format: "identifier == %@ OR identifier == %@",
                                      "chat_assistant_message", "chat_user_message")).firstMatch
            _ = anyMessage.waitForExistence(timeout: 20)
            sleep(3)
            attach(app, "2-chat-thread")
            app.navigationBars.buttons.firstMatch.tap()   // back to the list
            _ = app.collectionViews["chat_list"].firstMatch.waitForExistence(timeout: 10)
            sleep(1)
        }

        // New-chat composer (fresh empty thread).
        let newChat = app.buttons["chat_new"]
        if newChat.waitForExistence(timeout: 5) {
            newChat.tap()
            let composer = app.textFields["chat_composer"].firstMatch
            _ = composer.waitForExistence(timeout: 15)
            composer.tap()
            composer.typeText("What can you help me with?")
            sleep(1)
            attach(app, "3-chat-new")
        }
    }

    private func attach(_ app: XCUIApplication, _ name: String) {
        let shot = XCTAttachment(screenshot: app.screenshot())
        shot.name = name
        shot.lifetime = .keepAlways
        add(shot)
    }
}
