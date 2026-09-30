import XCTest
import LocalAuthentication
import CryptoKit
@testable import JoineryMailKit
@testable import JoineryKit

/// The phone's end-to-end reading and its AI judgement against captured
/// server envelopes and the browser's scripted-model vectors
/// (specs/fortress_mobile_apps.md § R4, R13; fixtures from
/// plugins/mailbox/tests/fixtures/fortress_api/ and device_ai_vectors.json).
@MainActor
final class FortressFlowTests: XCTestCase {

    private func envelope(_ name: String) throws -> JSONValue {
        try XCTUnwrap(try PortVectorTests.vector("fortress_api/\(name).json")["data"])
    }

    private func session(holding secret: Data?) -> (SessionController, FortressSession) {
        let client = APIClient(config: JoineryConfig(baseURL: URL(string: "https://example.invalid")!,
                                                     clientApp: "test", clientVersion: "0", appName: "Test"))
        let s = SessionController(client: client, keychain: KeychainStore(service: "fortress-flow-test"))
        let f = FortressSession(session: s)
        if let secret {
            s.heldKeys.hold(secret, scope: "mail")
            f.vault.refreshStatus()
        }
        return (s, f)
    }

    private func keys() throws -> JSONValue { try PortVectorTests.vector("fortress_api/fortress_capture_keys.json") }

    func testCapturedMailboxesParseTheFortressFields() throws {
        let home = try XCTUnwrap(MailboxHome(data: try envelope("fortress_mailboxes")))
        let box = try XCTUnwrap(home.mailboxes.first)
        XCTAssertTrue(box.isFortress)
        XCTAssertTrue(box.locked)
        XCTAssertTrue(box.own)
        XCTAssertEqual(box.newestUnreadID, 111270)
    }

    func testTheListOpensWithTheKeyAndShowsPlaceholdersWithout() throws {
        let k = try keys()
        let secret = try XCTUnwrap(Data(base64Encoded: k["mail_secret_b64"]?.stringValue ?? ""))
        XCTAssertEqual(try VaultCrypto.publicKey(fromSecret: secret), k["mail_public_b64"]?.stringValue)
        let page = try XCTUnwrap(ThreadPage(data: try envelope("fortress_thread_list")))
        XCTAssertTrue(page.fortress)
        XCTAssertNotNil(page.threads.first?.sealed)

        let (_, open) = session(holding: secret)
        let opened = open.openList(page)
        XCTAssertEqual(opened.threads.first?.subject, k["subject"]?.stringValue)
        XCTAssertNil(opened.threads.first?.placeholder)
        XCTAssertFalse(opened.threads.first?.sender.isEmpty ?? true)

        let (_, none) = session(holding: nil)
        let shut = none.openList(page)
        XCTAssertNotNil(shut.threads.first?.placeholder, "without the key a row says so, never '(no subject)' from '(unknown)'")
        XCTAssertEqual(shut.threads.first?.sender, "Encrypted")
    }

    func testAThreadOpensItsBodyAndNamesItsPartsFromTheManifest() async throws {
        let k = try keys()
        let secret = try XCTUnwrap(Data(base64Encoded: k["mail_secret_b64"]?.stringValue ?? ""))
        let thread = try XCTUnwrap(MailThread(data: try envelope("fortress_thread")))
        let m0 = try XCTUnwrap(thread.messages.first)
        XCTAssertTrue(m0.fortress)
        XCTAssertTrue(m0.attachments.allSatisfy { $0.url != nil }, "Fortress parts carry signed URLs")
        let (_, f) = session(holding: secret)
        let opened = await f.openThread(thread)
        let m = try XCTUnwrap(opened.messages.first)
        XCTAssertNil(m.placeholder)
        XCTAssertEqual(m.subject, k["subject"]?.stringValue)
        XCTAssertFalse(m.bodyPlain.isEmpty)
        XCTAssertTrue(m.canRespond)
        let pdf = try XCTUnwrap(m.attachments.first { $0.mimePart == k["pdf_mime_part"]?.stringValue })
        XCTAssertTrue(pdf.sealed)
        XCTAssertNotEqual(pdf.filename, "attachment", "named from the sealed manifest")
        XCTAssertEqual(pdf.ad, "mail:111270:att:2")
        // The stored bytes open under the row key with the part's AD.
        let key = try f.rowKey(messageID: m.id, sealedDek: m.sealed!.sealedDek)
        let plain = try VaultCrypto.openEdgeBytes(k["pdf_stored"]?.stringValue ?? "", key: key, ad: pdf.ad)
        XCTAssertEqual(String(decoding: plain, as: UTF8.self), k["pdf_plain"]?.stringValue)
        // What a reply quotes.
        let quote = try XCTUnwrap(f.sourceOpen(m))
        XCTAssertEqual(quote["subject"]?.stringValue, m.subject)
        // Locked: placeholders, no Reply.
        f.lock()
        let shut = await f.openThread(thread)
        XCTAssertEqual(shut.messages.first?.placeholder, .locked)
        XCTAssertFalse(shut.messages.first?.canRespond ?? true)
        XCTAssertNil(f.sourceOpen(shut.messages.first!))
    }

    func testCidRewriteKeepsUnknownReferences() {
        let html = "<img src=\"cid:a%40b\"><img src='cid:<zz>'><img src=cid:none>"
        let out = FortressSession.rewriteCids(html, map: ["a@b": "data:image/png;base64,AAA", "zz": "data:x"])
        // As the browser's inlineRewrite: the match stops at '>', so an
        // angle-bracketed reference keeps its closing bracket.
        XCTAssertEqual(out, "<img src=\"data:image/png;base64,AAA\"><img src='data:x>'><img src=cid:none>")
    }

    func testTheSealedBodyViewLoadsNothingRemote() {
        let wrapped = HTMLBodyView.wrap("<img src=\"https://tracker.example/p.gif\">", sealed: true)
        XCTAssertTrue(wrapped.contains("Content-Security-Policy"))
        XCTAssertTrue(wrapped.contains("default-src 'none'; img-src data:"))
        XCTAssertFalse(HTMLBodyView.wrap("x", sealed: false).contains("Content-Security-Policy"))
    }

    // MARK: The AI judgement against the browser's scripted model

    func testJudgementsReplayTheBrowsersRequestsAndPosts() async throws {
        let v = try PortVectorTests.vector("device_ai_vectors.json")
        let inputs = try XCTUnwrap(v["judge_inputs"])
        let entry = try XCTUnwrap(inputs["entry"])
        var opened: [String: String] = [:]
        for (k, val) in inputs["opened"]?.objectValue ?? [] { opened[k] = val.stringValue ?? "" }
        let ep = DeviceAIJudge.Endpoint(url: inputs["endpoint"]?["url"]?.stringValue ?? "",
                                        key: inputs["endpoint"]?["key"]?.stringValue ?? "",
                                        model: inputs["endpoint"]?["model"]?.stringValue ?? "")
        for j in v["judgements"]?.arrayValue ?? [] {
            let name = j["name"]?.stringValue ?? "?"
            var recipe = DeviceAIJudge.Recipe(json: j["recipe"]!)
            recipe.descriptor = v["descriptors"]?[recipe.jobID]
            var replies = j["replies"]?.arrayValue ?? []
            var requests: [URLRequest] = []
            var posts: [(String, [(key: String, value: JSONValue)])] = []
            let key = SymmetricKey(size: .bits256)
            let deps = DeviceAIJudge.Deps(
                open: { _ in opened },
                key: { _ in key },
                fetch: { req in
                    requests.append(req)
                    let r = replies.removeFirst()
                    return (r["status"]?.intValue ?? 200, (r["body"] ?? .null).encodedData())
                },
                post: { a, b in posts.append((a, b)); return .object([(key: "recorded", value: .bool(true))]) },
                epoch: { 0 })
            let out = await DeviceAIJudge.judge(entry: entry, recipe: recipe, endpoint: ep, deps: deps)

            let want = j["requests"]?.arrayValue ?? []
            XCTAssertEqual(requests.count, want.count, "\(name): calls")
            for (req, w) in zip(requests, want) {
                XCTAssertEqual(req.url?.absoluteString, w["url"]?.stringValue, "\(name): url")
                XCTAssertEqual(req.httpMethod, w["method"]?.stringValue)
                for (h, hv) in w["headers"]?.objectValue ?? [] {
                    XCTAssertEqual(req.value(forHTTPHeaderField: h), hv.stringValue, "\(name): header \(h)")
                }
                let body = try JSONValue.parse(req.httpBody ?? Data())
                XCTAssertEqual(body.encoded(), w["body"]?.encoded(), "\(name): body")
            }
            let wantPosts = j["posts"]?.arrayValue ?? []
            XCTAssertEqual(posts.count, wantPosts.count, "\(name): posts")
            for (p, w) in zip(posts, wantPosts) {
                XCTAssertEqual(p.0, w["action"]?.stringValue, "\(name): action")
                let body = JSONValue.object(p.1)
                for (field, wv) in w["body"]?.objectValue ?? [] {
                    if field == "fields" {
                        for (col, spec) in wv.objectValue ?? [] {
                            let ad = spec["sealed_under_row_dek_with_ad"]?.stringValue ?? ""
                            let sealed = body["fields"]?[col]?.stringValue ?? ""
                            let plain = try VaultCrypto.openField(sealed, key: key, ad: ad)
                            XCTAssertEqual(plain, j["outcome"]?["plaintext"]?.stringValue, "\(name): sealed \(col)")
                        }
                    } else {
                        XCTAssertEqual(body[field]?.encoded(), wv.encoded(), "\(name): \(field)")
                    }
                }
            }
            let o = j["outcome"]
            XCTAssertEqual(out.status, o?["status"]?.stringValue, "\(name): status")
            XCTAssertEqual(out.field, o?["field"]?.isNull == false ? o?["field"]?.stringValue : nil, "\(name): field")
            XCTAssertEqual(out.model, o?["model"]?.isNull == false ? o?["model"]?.stringValue : nil, "\(name): model")
            XCTAssertEqual(out.http, o?["http"]?.isNull == false ? o?["http"]?.intValue : nil, "\(name): http")
            if let reason = o?["reason"], !reason.isNull { XCTAssertEqual(out.reason, reason.stringValue, "\(name): reason") }
        }
    }

    // MARK: The relay pin

    func testRelayPinJudgementMatchesTheBrowser() throws {
        let v = try PortVectorTests.vector("relay_pin_vector.json")
        let secret = VaultCrypto.pkcs8(fromScalar: Data(hex: v["vault_secret_hex"]?.stringValue ?? ""))
        let id = v["relay_identity_public_key"]?.stringValue ?? ""
        let relay = JSONValue.object([(key: "statement", value: v["statement"]!), (key: "signature", value: v["signature"]!)])
        func answer(pin: JSONValue, statement: String? = nil) -> JSONValue {
            var r = relay
            if let statement { r = .object([(key: "statement", value: .string(statement)), (key: "signature", value: v["signature"]!)]) }
            return .object([(key: "relay_answer", value: .string(r.encoded())),
                            (key: "relay_identity_public_key", value: .string(id)), (key: "pin", value: pin)])
        }
        let pin = JSONValue.object([(key: "relay_identity_public_key", value: .string(id)), (key: "mac", value: v["pin_mac"]!)])
        let keys = ["YO6P+WWilqSUxSipdAY4nfvIGw9u4mFN3hBKas4ZBVY="]
        let alias = v["alias_id"]?.intValue ?? 0
        XCTAssertEqual(FortressWork.judge(answer: answer(pin: pin), aliasID: alias, address: "box@fortress.test", secret: secret, keys: keys),
                       .ok(firstUse: false, identity: id))
        XCTAssertEqual(FortressWork.judge(answer: answer(pin: .null), aliasID: alias, address: "box@fortress.test", secret: secret, keys: keys),
                       .ok(firstUse: true, identity: id))
        if case .bad(let r, _, _) = FortressWork.judge(answer: answer(pin: pin), aliasID: alias, address: "box@fortress.test",
                                                       secret: secret, keys: ["AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA="]) {
            XCTAssertEqual(r, "key")
        } else { XCTFail("another key must fail") }
        let changed = (v["statement"]?.stringValue ?? "").replacingOccurrences(of: "\"map_version\":7", with: "\"map_version\":8")
        if case .bad(let r, _, _) = FortressWork.judge(answer: answer(pin: pin, statement: changed), aliasID: alias,
                                                       address: "box@fortress.test", secret: secret, keys: keys) {
            XCTAssertEqual(r, "signature")
        } else { XCTFail("a changed statement must fail") }
        let forged = JSONValue.object([(key: "relay_identity_public_key", value: .string(id)),
                                       (key: "mac", value: .string("AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA="))])
        if case .bad(let r, _, _) = FortressWork.judge(answer: answer(pin: forged), aliasID: alias, address: "box@fortress.test",
                                                       secret: secret, keys: keys) {
            XCTAssertEqual(r, "pin")
        } else { XCTFail("a forged pin must fail") }
    }
}

/// The polling high-water mark (specs/fortress_mobile_apps.md § R15).
final class MailPollingTests: XCTestCase {
    private func box(_ id: Int, newest: Int?, unread: Int = 1, own: Bool = true, level: String = "standard") -> MailPolling.Box {
        MailPolling.Box(aliasID: id, address: "m\(id)@x.example", level: level, newestUnread: newest, unread: unread, own: own)
    }

    func testFirstCheckRecordsOnlyThenAHigherIdNotifiesOnce() {
        let first = MailPolling.decide([box(1, newest: 10)], marks: [:], enabled: nil, seeded: false)
        XCTAssertTrue(first.notify.isEmpty)
        XCTAssertEqual(first.marks[1], 10)
        let same = MailPolling.decide([box(1, newest: 10)], marks: first.marks, enabled: nil, seeded: true)
        XCTAssertTrue(same.notify.isEmpty, "nothing new, nothing shown")
        let higher = MailPolling.decide([box(1, newest: 12)], marks: first.marks, enabled: nil, seeded: true)
        XCTAssertEqual(higher.notify.map(\.aliasID), [1])
        XCTAssertEqual(higher.marks[1], 12)
        let again = MailPolling.decide([box(1, newest: 12)], marks: higher.marks, enabled: nil, seeded: true)
        XCTAssertTrue(again.notify.isEmpty, "once per arrival")
    }

    func testALowerOrClearedIdIsSilentAndTheMarkNeverFalls() {
        let d = MailPolling.decide([box(1, newest: 5)], marks: [1: 10], enabled: nil, seeded: true)
        XCTAssertTrue(d.notify.isEmpty)
        XCTAssertEqual(d.marks[1], 10)
        let read = MailPolling.decide([box(1, newest: nil, unread: 0)], marks: [1: 10], enabled: nil, seeded: true)
        XCTAssertTrue(read.notify.isEmpty)
        XCTAssertEqual(read.marks[1], 10)
    }

    func testOnlyTurnedOnMailboxesNotifyAndCountInTheBadge() {
        let boxes = [box(1, newest: 20, unread: 3), box(2, newest: 20, unread: 4, own: false)]
        let byDefault = MailPolling.decide(boxes, marks: [1: 1, 2: 1], enabled: nil, seeded: true)
        XCTAssertEqual(byDefault.notify.map(\.aliasID), [1], "by default, the mailboxes the person is a member of")
        XCTAssertEqual(byDefault.badge, 3)
        let chosen = MailPolling.decide(boxes, marks: [1: 1, 2: 1], enabled: [2], seeded: true)
        XCTAssertEqual(chosen.notify.map(\.aliasID), [2])
        XCTAssertEqual(chosen.badge, 4)
        let fresh = MailPolling.decide([box(3, newest: 7)], marks: [:], enabled: nil, seeded: true)
        XCTAssertTrue(fresh.notify.isEmpty, "a mailbox seen for the first time records only")
    }
}

/// Round-two rules (specs/fortress_mobile_apps.md § R13, B4, B7).
@MainActor
final class TransportRuleTests: XCTestCase {
    func testTheSiteIsHttpsOnly() async {
        let client = APIClient(config: JoineryConfig(baseURL: URL(string: "http://example.invalid")!,
                                                     clientApp: "t", clientVersion: "0", appName: "T"))
        client.setCredentials(APICredentials(publicKey: "pk", secretKey: "sk"))
        do {
            _ = try await client.submitAction("mailbox/mailboxes", body: .object([]))
            XCTFail("a plain-http site must be refused before anything is sent")
        } catch JoineryAPIError.server(let type, _, _) {
            XCTAssertEqual(type, "SecurityError")
        } catch {
            XCTFail("unexpected \(error)")
        }
        let https = APIClient(config: JoineryConfig(baseURL: URL(string: "https://example.invalid")!,
                                                    clientApp: "t", clientVersion: "0", appName: "T"))
        do {
            _ = try await https.fetchBytes("http://example.invalid/uploads/x.bin")
            XCTFail("a plain-http signed URL must be refused")
        } catch JoineryAPIError.server(let type, _, _) {
            XCTAssertEqual(type, "SecurityError")
        } catch {
            XCTFail("unexpected \(error)")
        }
    }

    func testTheModelIsCalledOnlyOnTheRegisteredOrigin() {
        let ai = DeviceAISettings(deviceKeys: DeviceKeyStore(service: "ai-rule-test", protection: .plain), userID: 999_001)
        let held = HeldKeys(observeApp: false)
        ai.baseURL = "http://192.168.1.20:11434/v1"
        ai.model = "qwen"
        ai.registeredOrigin = nil
        XCTAssertNil(ai.endpoint(held: held), "no registered origin, no call")
        ai.registeredOrigin = "http://192.168.1.20:11434"
        XCTAssertEqual(ai.endpoint(held: held)?.url, "http://192.168.1.20:11434/v1/chat/completions",
                       "plain http on the person's own network, when that is the registered origin")
        ai.registeredOrigin = "https://llm.example"
        XCTAssertNil(ai.endpoint(held: held), "http elsewhere than the registered origin is refused")
        ai.baseURL = "https://llm.example.evil.test/v1"
        XCTAssertNil(ai.endpoint(held: held), "another host is refused")
        ai.baseURL = "https://llm.example/v1"
        XCTAssertNotNil(ai.endpoint(held: held))
        XCTAssertFalse(DeviceAISettings.sameOrigin("https://llm.example:8443/v1", "https://llm.example"))
        XCTAssertTrue(DeviceAISettings.sameOrigin("https://LLM.example:443/v1", "https://llm.example"))
    }

    func testAMessageThisPhoneCannotOpenIsSkippedNotDropped() async {
        let deps = DeviceAIJudge.Deps(
            open: { _ in throw FortressError.message("cannot open") },
            key: { _ in SymmetricKey(size: .bits256) },
            fetch: { _ in XCTFail("no model call for an unopened message"); return (500, Data()) },
            post: { a, _ in XCTFail("nothing recorded: \(a)"); return .null },
            epoch: { 0 })
        let out = await DeviceAIJudge.judge(entry: .object([(key: "id", value: .number(1))]),
                                            recipe: DeviceAIJudge.Recipe(json: .object([])),
                                            endpoint: .init(url: "https://llm.example/v1/chat/completions", key: "", model: "m"),
                                            deps: deps)
        XCTAssertEqual(out.status, "skip")
    }
}

/// Review of 2026-09-30 (F1, F2, F9, F10, F20, F21).
@MainActor
final class ReviewFixTests: XCTestCase {
    func testOnlyNotFoundWipesAKey() {
        XCTAssertEqual(DeviceKeyStore.classify(errSecItemNotFound), .gone)
        for status in [errSecUserCanceled, errSecAuthFailed, errSecInteractionNotAllowed, errSecNotAvailable] {
            XCTAssertEqual(DeviceKeyStore.classify(status), .unavailable, "status \(status) keeps the key")
        }
    }

    func testADeadDeviceSecretMakesANewPairAndUnlinkWipesBoth() throws {
        let memory = MemoryBackend()
        let store = DeviceKeyStore(service: "review-f2", protection: .plain, items: memory)
        let first = try store.devicePublicKey()
        XCTAssertEqual(try store.devicePublicKey(), first, "a live key is kept")
        // The gated half gone (what a new face or finger does): a new pair.
        memory.store["device.x25519.secret"] = nil
        XCTAssertFalse(store.hasDeviceKey)
        let second = try store.devicePublicKey()
        XCTAssertNotEqual(second, first, "never bind a key whose secret is gone")
        XCTAssertNotNil(store.deviceSecret(context: nil).data)
        try store.storeVaultSecret(Data(repeating: 1, count: 48), scope: "mail")
        store.forgetDeviceKey()
        XCTAssertFalse(store.hasDeviceKey)
        XCTAssertFalse(store.hasVaultSecret(scope: "mail"), "unlink takes the scope secrets with it")
    }

    private func probe(_ pairs: [(String, JSONValue)]) -> DeviceVault.Probe {
        DeviceVault.Probe(data: .object(pairs.map { (key: $0.0, value: $0.1) }))!
    }

    func testTheProbeWipesOnlyOnAnOutrightAnswer() {
        let ok = probe([("set_up", .bool(true)), ("public_key", .string("K1")), ("held_by_this_device", .bool(true)),
                        ("device_linked", .bool(true))])
        XCTAssertEqual(DeviceVault.judge(ok, storedPublicKey: "K1", handedOverUnderThisKey: true), .keep)
        XCTAssertEqual(DeviceVault.judge(probe([]), storedPublicKey: "K1", handedOverUnderThisKey: true), .keep,
                       "an empty answer is unknown, not 'no'")
        XCTAssertEqual(DeviceVault.judge(probe([("held_by_this_device", .bool(false))]), storedPublicKey: "K1",
                                         handedOverUnderThisKey: true), .retired)
        XCTAssertEqual(DeviceVault.judge(probe([("set_up", .bool(true)), ("public_key", .string("K2"))]),
                                         storedPublicKey: "K1", handedOverUnderThisKey: true), .retired)
        XCTAssertEqual(DeviceVault.judge(probe([("set_up", .bool(true)), ("public_key", .string("K2")),
                                                ("pending_public_key", .string("K1"))]),
                                         storedPublicKey: "K1", handedOverUnderThisKey: true), .keep, "mid-rotation")
        XCTAssertEqual(DeviceVault.judge(probe([("device_linked", .bool(false))]), storedPublicKey: "K1",
                                         handedOverUnderThisKey: true), .unlinked)
        XCTAssertEqual(DeviceVault.judge(probe([("device_linked", .bool(false))]), storedPublicKey: nil,
                                         handedOverUnderThisKey: false), .keep,
                       "before the first handover there is no row: that is not an unlink")
    }

    func testDraftPartsAreKeyedByAttachmentNotName() {
        let a = MailOutgoingAttachment(filename: "photo.jpg", mimeType: "image/jpeg", data: Data([1]))
        let b = MailOutgoingAttachment(filename: "photo.jpg", mimeType: "image/jpeg", data: Data([2]))
        let first = DraftSession.partition(files: [a, b], uploaded: [:], keepSaved: ["draft:old"])
        XCTAssertEqual(first.fresh.map(\.id), [a.id, b.id], "two files of one name are two parts")
        XCTAssertEqual(first.keep, ["draft:old"])
        let later = DraftSession.partition(files: [a, b], uploaded: [a.id: "draft:a"], keepSaved: [])
        XCTAssertEqual(later.fresh.map(\.id), [b.id])
        XCTAssertEqual(later.keep, ["draft:a"])
        let removed = DraftSession.partition(files: [b], uploaded: [a.id: "draft:a", b.id: "draft:b"], keepSaved: [])
        XCTAssertEqual(removed.keep, ["draft:b"], "a removed attachment's part is not kept")
    }

    func testADraftWithAColumnThatWillNotOpenFailsTheOpen() async throws {
        let priv = Curve25519.KeyAgreement.PrivateKey()
        let secret = VaultCrypto.pkcs8(fromScalar: priv.rawRepresentation)
        let pub = VaultCrypto.b64(priv.publicKey.rawRepresentation)
        let dek = VaultCrypto.newDek()
        let client = APIClient(config: JoineryConfig(baseURL: URL(string: "https://example.invalid")!,
                                                     clientApp: "t", clientVersion: "0", appName: "T"))
        let s = SessionController(client: client, keychain: KeychainStore(service: "review-f21"))
        let f = FortressSession(session: s)
        s.heldKeys.hold(secret, scope: "mail")
        let good = try VaultCrypto.sealField("Hello", key: dek, ad: "mail:7:iem_subject")
        let wrongAD = try VaultCrypto.sealField("Body", key: dek, ad: "mail:8:iem_body_plain")
        let data = JSONValue.object([
            (key: "draft_id", value: .number(7)), (key: "fortress", value: .bool(true)),
            (key: "sealed_ad_prefix", value: .string("mail:")),
            (key: "sealed", value: .object([
                (key: "sealed_dek", value: .string(try VaultCrypto.sealDek(dek, scope: "mail", toPublicKey: pub))),
                (key: "iem_subject", value: .string(good)), (key: "iem_body_plain", value: .string(wrongAD)),
            ])),
            (key: "parts", value: .array([])),
        ])
        let d = DraftSession(api: MailAPI(client: client), fortress: f, isFortress: true, draftID: 7)
        do {
            _ = try await d.open(data)
            XCTFail("a column that will not open must fail the open, never show blank")
        } catch {}
    }

    func testUnparseableRelayItemsAreRememberedAndBounded() {
        UserDefaults.standard.removeObject(forKey: FortressWork.skipKey)
        FortressWork.remembered = Array(1...250)
        XCTAssertEqual(FortressWork.remembered.count, 200, "the newest 200")
        XCTAssertEqual(FortressWork.remembered.last, 250)
        UserDefaults.standard.removeObject(forKey: FortressWork.skipKey)
        XCTAssertEqual(FortressWork.maxPendingBytes, 25 * 1024 * 1024)
    }

    func testABackgroundTaskRunsWithNoKeyHeld() {
        let held = HeldKeys(observeApp: false)
        held.hold(Data([1, 2, 3]), scope: "mail")
        HeldKeys.dropForBackgroundTask()
        XCTAssertFalse(held.isOpen("mail"))
    }
}


/// Review re-check of 2026-09-30 (R1, R4, R8, R9).
@MainActor
final class ReviewRecheckTests: XCTestCase {
    func testAnUnknownBiometricStateIsNeverAChange() {
        let a = Data([1, 2, 3]), b = Data([4, 5, 6])
        XCTAssertFalse(DeviceKeyStore.domainStateChanged(recorded: a, current: nil), "a lockout is not a new face")
        XCTAssertFalse(DeviceKeyStore.domainStateChanged(recorded: nil, current: b), "no recorded state is not a change")
        XCTAssertFalse(DeviceKeyStore.domainStateChanged(recorded: a, current: a))
        XCTAssertTrue(DeviceKeyStore.domainStateChanged(recorded: a, current: b))
    }

    /// A store whose listing comes back empty, as an access-controlled query can.
    final class BlindListing: ItemBackend {
        let inner = MemoryBackend()
        func add(account: String, data: Data, access: SecAccessControl?, gated: Bool) -> OSStatus {
            inner.add(account: account, data: data, access: access, gated: gated)
        }
        func copy(account: String, context: LAContext?) -> (OSStatus, Data?) { inner.copy(account: account, context: context) }
        func exists(account: String) -> Bool { inner.exists(account: account) }
        func delete(account: String) { inner.delete(account: account) }
        func accounts() -> [String] { [] }
    }

    func testRegenerationWipesTheScopeSecretsEvenWhenTheListingIsBlind() throws {
        let items = BlindListing()
        let store = DeviceKeyStore(service: "recheck-r4", protection: .plain, items: items)
        _ = try store.devicePublicKey()
        try store.storeVaultSecret(Data(repeating: 9, count: 48), scope: "mail")
        try store.storeSecret(Data("k".utf8), name: "deviceai.key")
        items.inner.store["device.x25519.secret"] = nil        // the gated half gone
        _ = try store.devicePublicKey()                        // a new pair
        XCTAssertFalse(store.hasVaultSecret(scope: "mail"), "the old scope secret goes with the old pair")
        XCTAssertNil(store.secret(name: "deviceai.key", context: nil))
    }

    func testTheNotificationFindsTheNewestUnreadInOneThread() throws {
        func row(_ key: String, latest: Int, unread: Int) -> ThreadSummary {
            ThreadSummary(json: .object([(key: "thread_key", value: .string(key)), (key: "latest_id", value: .number(Double(latest))),
                                         (key: "unread_count", value: .number(Double(unread)))]))!
        }
        let threads = [row("read", latest: 90, unread: 0), row("older", latest: 40, unread: 1), row("holds", latest: 50, unread: 2)]
        XCTAssertEqual(MailPolling.threadHolding(50, in: threads)?.threadKey, "holds")
        XCTAssertNil(MailPolling.threadHolding(95, in: threads), "not on the page: the mailbox name only")
    }

    func testThePendingBannerTellsTheTruth() {
        XCTAssertNil(FortressWork.pendingNote(remaining: 0, tooLarge: 0, skipped: false))
        XCTAssertEqual(FortressWork.pendingNote(remaining: 2, tooLarge: 2, skipped: false),
                       "2 new end-to-end messages are too large to open on this phone; open them on a computer.")
        XCTAssertEqual(FortressWork.pendingNote(remaining: 3, tooLarge: 1, skipped: true),
                       "1 new end-to-end message is too large to open on this phone; open it on a computer. 2 new end-to-end messages could not be opened on this phone. Another of your devices may open them.")
    }

    func testOnlyTheSitesAuthenticationErrorSignsOut() {
        XCTAssertTrue(SessionController.signsOut(on: JoineryAPIError.authentication(message: "revoked", status: 401)))
        XCTAssertFalse(SessionController.signsOut(on: JoineryAPIError.malformedResponse), "a captive portal keeps the session")
        XCTAssertFalse(SessionController.signsOut(on: JoineryAPIError.network(underlying: URLError(.notConnectedToInternet))))
        XCTAssertFalse(SessionController.signsOut(on: JoineryAPIError.server(errortype: "ActionError", message: "x", status: 500)))
    }
}


/// Final review (X2, X3).
@MainActor
final class ReviewFinalTests: XCTestCase {
    /// Answers every request with one probe envelope.
    final class ProbeStub: URLProtocol {
        static var body = Data()
        override class func canInit(with request: URLRequest) -> Bool { true }
        override class func canonicalRequest(for request: URLRequest) -> URLRequest { request }
        override func startLoading() {
            let r = HTTPURLResponse(url: request.url!, statusCode: 200, httpVersion: nil,
                                    headerFields: ["Content-Type": "application/json"])!
            client?.urlProtocol(self, didReceive: r, cacheStoragePolicy: .notAllowed)
            client?.urlProtocol(self, didLoad: Self.body)
            client?.urlProtocolDidFinishLoading(self)
        }
        override func stopLoading() {}
    }

    /// Reads the device secret as "not readable now" while `flaky` is set.
    final class FlakyBackend: ItemBackend {
        let inner = MemoryBackend()
        var flaky = false
        func add(account: String, data: Data, access: SecAccessControl?, gated: Bool) -> OSStatus {
            inner.add(account: account, data: data, access: access, gated: gated)
        }
        func copy(account: String, context: LAContext?) -> (OSStatus, Data?) {
            if flaky && account == "device.x25519.secret" { return (errSecAuthFailed, nil) }
            return inner.copy(account: account, context: context)
        }
        func exists(account: String) -> Bool { inner.exists(account: account) }
        func delete(account: String) { inner.delete(account: account) }
        func accounts() -> [String] { inner.accounts() }
    }

    func testNoPairIsMintedWithoutAKnownEnrollment() {
        let items = MemoryBackend()
        let blind = DeviceKeyStore(service: "final-x2", protection: .biometric, items: items, domainState: { nil })
        XCTAssertThrowsError(try blind.devicePublicKey()) { e in
            guard case DeviceKeyStore.Failure.unavailable = e else { return XCTFail("\(e)") }
        }
        XCTAssertTrue(items.store.isEmpty, "nothing written")
    }

    func testAKeyNotReadableNowKeepsTheDeviceKeyAndTheHandover() async throws {
        let backend = FlakyBackend()
        let store = DeviceKeyStore(service: "final-x3", protection: .plain, items: backend)
        let devicePub = try store.devicePublicKey()
        let vaultPriv = Curve25519.KeyAgreement.PrivateKey()
        let vaultPub = VaultCrypto.b64(vaultPriv.publicKey.rawRepresentation)
        let blob = try VaultCrypto.seal(VaultCrypto.pkcs8(fromScalar: vaultPriv.rawRepresentation), toPublicKey: devicePub)
        ProbeStub.body = JSONValue.object([(key: "data", value: .object([
            (key: "set_up", value: .bool(true)), (key: "public_key", value: .string(vaultPub)),
            (key: "held_by_this_device", value: .bool(true)), (key: "device_linked", value: .bool(true)),
        ]))]).encodedData()
        let cfg = URLSessionConfiguration.ephemeral
        cfg.protocolClasses = [ProbeStub.self]
        let client = APIClient(config: JoineryConfig(baseURL: URL(string: "https://example.invalid")!,
                                                     clientApp: "t", clientVersion: "0", appName: "T"),
                               urlSession: URLSession(configuration: cfg))
        let session = SessionController(client: client, keychain: KeychainStore(service: "final-x3"),
                                        deviceKeys: store, heldKeys: HeldKeys(observeApp: false))
        let vault = DeviceVault.shared(session: session, scope: "mail")

        backend.flaky = true
        vault.pendingHandoverForTest(blob)
        do { try await vault.retryHandover(); XCTFail("an unreadable key cannot keep the handover") } catch {}
        XCTAssertTrue(store.hasDeviceKey, "the device key is kept")
        XCTAssertEqual(vault.pendingHandover, blob, "the handover stays for a retry")

        backend.flaky = false
        // L3: a handover sealed to another key never opens; it is dropped, so
        // Try again starts a new ceremony instead of retrying it forever.
        let stranger = try VaultCrypto.seal(Data(repeating: 1, count: 48),
                                            toPublicKey: VaultCrypto.b64(Curve25519.KeyAgreement.PrivateKey().publicKey.rawRepresentation))
        vault.pendingHandoverForTest(stranger)
        do { try await vault.retryHandover(); XCTFail("a stale handover cannot open") } catch {}
        XCTAssertNil(vault.pendingHandover, "a handover that can never open is dropped")
        XCTAssertTrue(store.hasDeviceKey, "the device key is kept")

        vault.pendingHandoverForTest(blob)
        try await vault.retryHandover()
        XCTAssertNil(vault.pendingHandover)
        XCTAssertEqual(vault.status, .open)
        XCTAssertEqual(try VaultCrypto.publicKey(fromSecret: vault.heldSecret ?? Data()), vaultPub)
    }
}
