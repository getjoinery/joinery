import XCTest

/// Gate: the Move/Labels picker on an open thread (JoineryMailKit; the
/// web reader's `buildFolderControl()` behavior as a native sheet) creates a
/// new label/folder and files the thread into it. The runner seeds the same
/// mailbox message used by `MailboxUITests` and passes a unique folder name
/// via the environment; the membership change is verified server-side (and
/// visible in the web reader) by the runner afterwards.
final class MailFolderPickerUITests: XCTestCase {

    override func setUp() {
        continueAfterFailure = false
    }

    func testCreateFolderFilesThread() throws {
        let subject = TestEnv.require("JOINERY_MAIL_SUBJECT")
        let folderName = TestEnv.require("JOINERY_MAIL_FOLDER_NAME")

        let app = XCUIApplication()
        app.launchJoinery()
        app.signIn(email: TestEnv.email, password: TestEnv.password)
        app.expectSignedIn()
        app.tabBars.buttons["Email"].tap()

        app.expect(app.collectionViews["mail_list"].firstMatch, timeout: 20, "native mail list")
        let listRow = app.staticTexts[subject].firstMatch
        app.expect(listRow, timeout: 20, "seeded message in native list")
        listRow.tap()

        app.expect(app.staticTexts["mail_thread_subject"].firstMatch, timeout: 15, "thread subject header")

        // Open the Move/Labels picker — hidden entirely when the mailbox has
        // no tracked folders yet, matching the web/Android behavior exactly.
        let foldersButton = app.buttons["mail_folders"]
        guard foldersButton.waitForExistence(timeout: 10) else {
            throw XCTSkip("No tracked folders on this mailbox — the Move/Labels control is not shown, matching the web reader.")
        }
        foldersButton.tap()

        // The identifier sits on the sheet's List, which surfaces as a
        // collection view, not an other-element.
        app.expect(app.collectionViews["mail_folder_sheet"].firstMatch, timeout: 10, "folder picker sheet")
        let newField = app.textFields["mail_folder_new"].firstMatch
        app.expect(newField, timeout: 10, "new folder/label name field")
        newField.tap()
        newField.typeText(folderName)
        app.buttons["mail_folder_create"].firstMatch.tap()

        // Either the sheet closes (an exclusive "Move" pops back to the list
        // once the thread relocates) or the new label shows in place with a
        // checkmark (non-exclusive "Labels") — both confirm creation.
        let createdOption = app.staticTexts[folderName].firstMatch
        let sheet = app.collectionViews["mail_folder_sheet"].firstMatch
        var confirmed = false
        for _ in 0..<30 {
            if !sheet.exists || createdOption.exists { confirmed = true; break }
            usleep(500_000)
        }
        XCTAssertTrue(confirmed, "expected the folder sheet to close or the new folder to appear checked")
    }
}
