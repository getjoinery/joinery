import XCTest

/// Gate: end-to-end (Fortress) mail on the phone (specs/fortress_mobile_apps.md
/// WP3, WP5, WP7, WP8). The runner (public_html/tests/functional/ios/
/// fortress_gate.sh) makes a Fortress fixture with a known mail key, seeds one
/// message and one relay-sealed arrival, and approves this phone's enrollment
/// from the dev box with the code this suite writes to JOINERY_FMA_CODE_FILE.
/// The keystore runs without Face ID (`--plain-keystore`, debug builds only):
/// the simulator cannot present a face.
final class FortressMailUITests: XCTestCase {

    override func setUp() {
        continueAfterFailure = false
    }

    private func shot(_ app: XCUIApplication, _ name: String) {
        let a = XCTAttachment(screenshot: app.screenshot())
        a.name = name
        a.lifetime = .keepAlways
        add(a)
    }

    private func waitGone(_ element: XCUIElement, _ timeout: TimeInterval, _ label: String) {
        let gone = XCTNSPredicateExpectation(predicate: NSPredicate(format: "exists == false"), object: element)
        XCTAssertEqual(XCTWaiter.wait(for: [gone], timeout: timeout), .completed, label)
    }

    private func text(_ app: XCUIApplication, containing s: String) -> XCUIElement {
        app.staticTexts.matching(NSPredicate(format: "label CONTAINS %@", s)).firstMatch
    }

    /// Pull the list until a row containing `s` shows (mail parsed on the phone).
    private func refreshUntil(_ app: XCUIApplication, _ s: String, timeout: TimeInterval) -> Bool {
        let deadline = Date().addingTimeInterval(timeout)
        let row = text(app, containing: s)
        while Date() < deadline {
            if row.waitForExistence(timeout: 5) { return true }
            let list = app.collectionViews["mail_list"].firstMatch
            list.swipeDown()
        }
        return row.exists
    }

    /// The toolbar menu, tapped at its centre (its nested button refuses the
    /// accessibility scroll a plain tap asks for).
    private func openViewMenu(_ app: XCUIApplication) {
        let menu = app.buttons["mail_view_menu"].firstMatch
        app.expect(menu, timeout: 10, "the view menu")
        menu.coordinate(withNormalizedOffset: CGVector(dx: 0.5, dy: 0.5)).tap()
    }

    func testEnrollReadSearchReplyDraftAndLock() throws {
        let word = TestEnv.require("JOINERY_FMA_WORD")
        let relayWord = TestEnv.require("JOINERY_FMA_RELAY_WORD")
        let codeFile = TestEnv.require("JOINERY_FMA_CODE_FILE")
        let replyText = TestEnv.require("JOINERY_FMA_REPLY_TEXT")
        let draftSubject = TestEnv.require("JOINERY_FMA_DRAFT_SUBJECT")
        let selfAddress = TestEnv.require("JOINERY_FMA_ADDRESS")
        let controlFile = TestEnv.require("JOINERY_FMA_CONTROL_FILE")
        let attachName = TestEnv.require("JOINERY_FMA_ATTACH_NAME")
        let aiBase = TestEnv.require("JOINERY_FMA_AI_BASE")
        let afterWord = TestEnv.require("JOINERY_FMA_AFTER_WORD")

        let app = XCUIApplication()
        app.launchArguments += ["--plain-keystore"]
        app.launchEnvironment["JOINERY_TEST_ATTACHMENT"] = attachName
        app.launchJoinery()
        app.signIn(email: TestEnv.email, password: TestEnv.password)
        app.expectSignedIn()
        app.tabBars.buttons["Email"].tap()
        app.expect(app.collectionViews["mail_list"].firstMatch, timeout: 20, "native mail list")

        // B1: without the key the mailbox says it is end-to-end, never blank rows.
        app.expect(app.staticTexts["mail_fortress_banner"].firstMatch, timeout: 20, "the end-to-end banner")
        XCTAssertFalse(app.staticTexts["(no subject)"].exists, "no blank Fortress rows")
        shot(app, "01-before-enroll")

        // Enroll: the code goes to the runner, which approves from the dev box.
        app.buttons["mail_fortress_enroll"].tap()
        let code = app.staticTexts["mail_enroll_code"].firstMatch
        app.expect(code, timeout: 20, "the enroll code")
        try code.label.write(toFile: codeFile, atomically: true, encoding: .utf8)
        shot(app, "02-enroll-code")
        waitGone(code, 120, "the phone should receive its key once the runner approves")

        // Read: the row opens on the phone; the body and the named part too.
        let row = text(app, containing: word)
        app.expect(row, timeout: 30, "the Fortress row opened on the phone")
        shot(app, "03-list-open")
        row.tap()
        app.expect(text(app, containing: "figures for the quarter"), timeout: 20, "the opened body")
        let chip = app.buttons.matching(NSPredicate(format: "label CONTAINS %@", "\(word)-report.pdf")).firstMatch
        app.expect(chip, timeout: 10, "the part named from the sealed manifest")
        shot(app, "04-thread-open")
        chip.tap()
        let share = app.otherElements["ActivityListView"].firstMatch
        if share.waitForExistence(timeout: 20) {
            shot(app, "05-part-opened")
            if app.buttons["Close"].exists { app.buttons["Close"].tap() } else { share.swipeDown(velocity: .fast) }
        } else {
            XCTFail("the opened part did not reach the share sheet")
        }

        // Forward first (Reply/Forward act on the thread's latest message, which
        // the reply below replaces): the opened parts go with it.
        let forward = app.buttons["mail_forward"].firstMatch
        app.expect(forward, timeout: 15, "Forward on an opened message")
        forward.tap()
        let fto = app.textFields["mail_compose_to"].firstMatch
        app.expect(fto, timeout: 10, "forward To")
        fto.tap(); fto.typeText(selfAddress)
        let fsend = app.buttons["mail_compose_send"].firstMatch
        fsend.tap()
        waitGone(fsend, 60, "the forward should send with its parts")
        // Reply: the quote comes from what the phone opened (source_open).
        let reply = app.buttons["mail_reply"].firstMatch
        app.expect(reply, timeout: 10, "Reply on an opened message")
        reply.tap()
        let body = app.textViews["mail_compose_body"].firstMatch
        app.expect(body, timeout: 10, "compose body")
        body.tap()
        body.typeText(replyText)
        let send = app.buttons["mail_compose_send"].firstMatch
        send.tap()
        waitGone(send, 40, "the reply should send and close the sheet")

        app.navigationBars.buttons.element(boundBy: 0).tap()

        // WP7: the relay-sealed arrival is parsed on this phone and stored.
        XCTAssertTrue(refreshUntil(app, relayWord, timeout: 120), "the relay-sealed message opened on the phone")
        shot(app, "06-relay-parsed")

        // WP5: search on the phone's own index finds a body word.
        let search = app.searchFields.firstMatch
        if !search.exists { app.collectionViews["mail_list"].firstMatch.swipeDown() }
        app.expect(search, timeout: 10, "search field")
        search.tap()
        search.typeText("\(word)\n")
        app.expect(text(app, containing: word), timeout: 60, "a device-search hit")
        shot(app, "07-search")
        // Leave search mode so the toolbar is back.
        let cancelSearch = app.navigationBars.buttons["Cancel"].firstMatch
        if cancelSearch.exists { cancelSearch.tap() }

        // WP8: a sealed draft, saved on leaving, reopened, sent.
        app.buttons["mail_new_message"].tap()
        let to = app.textFields["mail_compose_to"].firstMatch
        app.expect(to, timeout: 10, "compose To")
        to.tap(); to.typeText(selfAddress)
        let subject = app.textFields["mail_compose_subject"].firstMatch
        subject.tap(); subject.typeText(draftSubject)
        let dbody = app.textViews["mail_compose_body"].firstMatch
        dbody.tap(); dbody.typeText("Draft body \(draftSubject)")
        app.buttons["mail_compose_attach"].firstMatch.coordinate(withNormalizedOffset: CGVector(dx: 0.5, dy: 0.5)).tap()
        app.buttons["Test file"].firstMatch.tap()
        app.expect(app.staticTexts[attachName].firstMatch, timeout: 10, "the attachment in the compose")
        app.expect(app.staticTexts["mail_compose_draft_note"].firstMatch, timeout: 20, "the autosave")
        app.buttons["mail_compose_cancel"].tap()
        waitGone(to, 20, "leaving the compose keeps the draft and closes the sheet")
        app.expect(app.buttons["mail_view_menu"].firstMatch, timeout: 10, "the view menu")
        openViewMenu(app)
        app.buttons["Drafts"].firstMatch.tap()
        let draftRow = text(app, containing: draftSubject)
        XCTAssertTrue(refreshUntil(app, draftSubject, timeout: 30), "the draft in Drafts")
        shot(app, "08-drafts")
        draftRow.tap()
        let reopened = app.textFields["mail_compose_subject"].firstMatch
        app.expect(reopened, timeout: 15, "the reopened draft")
        XCTAssertEqual(reopened.value as? String, draftSubject)
        // The part saved with the draft, sealed on the server; the send opens it
        // from its signed URL with the draft's key.
        app.expect(app.staticTexts[attachName].firstMatch, timeout: 15, "the saved attachment on the reopened draft")
        app.buttons["mail_compose_send"].tap()
        waitGone(app.buttons["mail_compose_send"].firstMatch, 40, "the draft should send")
        openViewMenu(app)
        app.buttons["Inbox"].firstMatch.tap()

        // Lock: placeholders, one Unlock, content back.
        openViewMenu(app)
        app.buttons["Mail settings"].tap()
        app.buttons["Lock now"].tap()
        app.buttons["Done"].tap()
        let unlock = app.buttons["mail_fortress_unlock"].firstMatch
        app.expect(unlock, timeout: 15, "Unlock after a lock")
        XCTAssertFalse(text(app, containing: word).exists, "no plaintext while locked")
        shot(app, "09-locked")
        unlock.tap()
        app.expect(text(app, containing: word), timeout: 20, "content back after unlock")
        shot(app, "10-unlocked")

        // R13: the person's own model (a stand-in on the mini) judges the mail.
        openViewMenu(app)
        app.buttons["Mail settings"].tap()
        let base = app.textFields["mail_ai_base"].firstMatch
        for _ in 0..<4 where !base.exists { app.swipeUp() }
        app.expect(base, timeout: 10, "the AI address field")
        base.tap(); base.typeText(aiBase)
        let modelField = app.textFields["mail_ai_model"].firstMatch
        modelField.tap(); modelField.typeText("stand-in")
        app.buttons["mail_ai_test"].firstMatch.tap()
        app.expect(text(app, containing: "AI is ready"), timeout: 30, "the Test reaches the stand-in")
        app.buttons["Done"].tap()
        XCUIDevice.shared.press(.home)
        sleep(2)
        app.activate()
        sleep(8)
        shot(app, "11a-ai-after-return")
        app.expect(text(app, containing: "judged on this phone"), timeout: 120, "the drain judged the mail")
        shot(app, "11-ai-judged")

        // B6: the mail key is rotated on the server while the phone holds it
        // locked; the phone lets go of it on its next return and offers
        // Enroll, not Unlock (B5).
        openViewMenu(app)
        app.buttons["Mail settings"].tap()
        let lockNow = app.buttons["Lock now"].firstMatch
        app.expect(lockNow, timeout: 10, "Lock now")
        lockNow.tap()
        app.buttons["Done"].tap()
        app.expect(app.buttons["mail_fortress_unlock"].firstMatch, timeout: 15, "locked before the rotation")
        try "retire".write(toFile: controlFile, atomically: true, encoding: .utf8)
        var retired = false
        for _ in 0..<60 {
            if (try? String(contentsOfFile: controlFile, encoding: .utf8)) == "retired" { retired = true; break }
            sleep(2)
        }
        XCTAssertTrue(retired, "the runner should retire the key")
        XCUIDevice.shared.press(.home)
        sleep(2)
        app.activate()
        app.expect(text(app, containing: "Your mail key changed"), timeout: 30, "the phone noticed the retired key")
        XCTAssertFalse(app.buttons["mail_fortress_unlock"].exists, "a wiped key offers Enroll, not Unlock")
        shot(app, "12-key-retired")
        app.buttons["mail_fortress_enroll"].tap()
        let code2 = app.staticTexts["mail_enroll_code"].firstMatch
        app.expect(code2, timeout: 20, "a new enroll code")
        try code2.label.write(toFile: codeFile, atomically: true, encoding: .utf8)
        waitGone(code2, 120, "the phone should receive the new key")
        XCTAssertTrue(refreshUntil(app, afterWord, timeout: 60), "mail sealed to the new key opens")
        shot(app, "13-new-key")
    }
}

/// Gate: new-mail notifications by polling (specs/fortress_mobile_apps.md
/// § R15). The Simulator cannot fire a BGAppRefreshTask on demand, so the
/// debug build checks on every return to the foreground (`--poll-on-foreground`)
/// through the same MailPolling.check(). The runner delivers a message when
/// this suite writes JOINERY_FMA_POLL_FILE and answers "delivered" in it.
final class MailPollingUITests: XCTestCase {
    override func setUp() { continueAfterFailure = false }

    func testNewMailNotifiesWithTheMailboxOnly() throws {
        let pollFile = TestEnv.require("JOINERY_FMA_POLL_FILE")
        let address = TestEnv.require("JOINERY_FMA_ADDRESS")
        let app = XCUIApplication()
        app.launchArguments += ["--plain-keystore", "--poll-on-foreground"]
        app.launchJoinery()
        app.signIn(email: TestEnv.email, password: TestEnv.password)
        app.expectSignedIn()
        app.tabBars.buttons["Email"].tap()
        app.expect(app.collectionViews["mail_list"].firstMatch, timeout: 20, "mail list")

        // Turn the mailbox's notifications on (and allow them).
        let menu = app.buttons["mail_view_menu"].firstMatch
        menu.coordinate(withNormalizedOffset: CGVector(dx: 0.5, dy: 0.5)).tap()
        app.buttons["Mail settings"].tap()
        let toggle = app.switches.matching(NSPredicate(format: "label CONTAINS %@", address)).firstMatch
        for _ in 0..<6 where !toggle.exists { app.swipeUp() }
        app.expect(toggle, timeout: 10, "the mailbox's notification switch")
        if (toggle.value as? String) == "1" {
            toggle.coordinate(withNormalizedOffset: CGVector(dx: 0.9, dy: 0.5)).tap()   // off, then on: asks for permission
        }
        toggle.coordinate(withNormalizedOffset: CGVector(dx: 0.9, dy: 0.5)).tap()
        let springboard = XCUIApplication(bundleIdentifier: "com.apple.springboard")
        let allow = springboard.buttons["Allow"]
        if allow.waitForExistence(timeout: 8) { allow.tap() }
        app.buttons["Done"].tap()

        // The first check records marks; background and return to take it.
        XCUIDevice.shared.press(.home)
        sleep(2)
        app.activate()
        sleep(6)
        try "ready".write(toFile: pollFile, atomically: true, encoding: .utf8)
        var delivered = false
        for _ in 0..<60 {
            if (try? String(contentsOfFile: pollFile, encoding: .utf8)) == "delivered" { delivered = true; break }
            sleep(2)
        }
        XCTAssertTrue(delivered, "the runner should deliver a message")
        XCUIDevice.shared.press(.home)
        sleep(2)
        app.activate()

        let banner = springboard.descendants(matching: .any)
            .matching(NSPredicate(format: "label CONTAINS %@", "New message in \(address)")).firstMatch
        let seen = banner.waitForExistence(timeout: 30)
        if !seen { print("FMA-SPRINGBOARD-BEGIN\n\(springboard.debugDescription)\nFMA-SPRINGBOARD-END") }
        let shot = XCTAttachment(screenshot: XCUIScreen.main.screenshot())
        shot.name = "poll-notification"; shot.lifetime = .keepAlways; add(shot)
        XCTAssertTrue(seen, "a notification naming the mailbox")
        // Fortress: the mailbox only, never a sender or subject.
        XCTAssertFalse(springboard.descendants(matching: .any)
            .matching(NSPredicate(format: "label CONTAINS %@", "Walk Sender")).firstMatch.exists)
    }
}
