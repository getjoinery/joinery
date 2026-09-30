import Foundation
import CryptoKit
import Network
import JoineryKit

/// What the phone does with end-to-end mail while the mail key is held and the
/// mailbox is on screen — never in the background (specs/fortress_mobile_apps.md
/// § R7, R13, R14): parse what a relay sealed to the key (and run the mailbox's
/// rules on it), check the relay pin, apply rules to existing mail, and judge
/// mail on the person's own AI model. One run at a time; a lock stops it.
@MainActor
public final class FortressWork: ObservableObject {
    @Published public private(set) var pendingLine: String?
    @Published public private(set) var aiLine: String?
    @Published public private(set) var relayAlarm: RelayAlarm?

    public struct RelayAlarm: Identifiable, Equatable {
        public let id = UUID()
        public let address: String
        public let reason: String
        public let expected: String
        public let reported: String
    }

    let fortress: FortressSession
    let api: MailAPI
    let ai: DeviceAISettings
    private var running: Task<Void, Never>?
    private var pinsChecked = false

    public init(fortress: FortressSession, api: MailAPI, ai: DeviceAISettings) {
        self.fortress = fortress
        self.api = api
        self.ai = ai
    }

    private var epoch: Int { fortress.lockEpoch }

    /// Run everything once (after an unlock, or opening the mailbox with the key held).
    public func run(mailboxes: [Mailbox], afterStored: @escaping () async -> Void) {
        guard running == nil, fortress.isOpen else { return }
        running = Task { @MainActor in
            defer { running = nil }
            let stored = await drainPending(mailboxes: mailboxes)
            if stored > 0 { await afterStored() }
            await checkRelayPins(mailboxes: mailboxes)
            await ruleBacklog()
            let judged = await aiDrain(mailboxes: mailboxes)
            if judged > 0 { await afterStored() }
        }
    }

    // MARK: Relay-sealed arrivals (§ R7)

    static let fieldMax = ["iem_subject": 4000, "iem_sender": 500]

    /// JavaScript's String.slice(0, n): UTF-16 units, never splitting a pair.
    nonisolated static func sliceUTF16(_ s: String, _ n: Int) -> String {
        guard s.utf16.count > n else { return s }
        var out = String.UnicodeScalarView()
        var used = 0
        for sc in s.unicodeScalars {
            let w = sc.utf16.count
            if used + w > n { break }
            out.append(sc)
            used += w
        }
        return String(out)
    }

    /// The parsed columns the parse store takes, before sealing (sealAndStore's values).
    nonisolated static func storeValues(_ p: MailMime.Parsed, snippetFallback: String) -> [String: String] {
        let manifest = p.attachments.isEmpty ? "" : JSONValue.array(p.attachments.map { a in
            .object([
                (key: "mime_part", value: .string(a.mimePart)), (key: "filename", value: .string(a.filename)),
                (key: "content_type", value: .string(a.contentType)), (key: "content_id", value: .string(a.contentID)),
                (key: "inline", value: .bool(a.inline)), (key: "size", value: .number(Double(a.bytes.count))),
            ])
        }).encoded()
        var v: [String: String] = [
            "iem_sender": p.from, "iem_subject": p.subject, "iem_body_plain": p.textPlain, "iem_body_html": p.textHTML,
            "iem_raw_headers": p.headers, "iem_to": p.to, "iem_cc": p.cc, "iem_attachment_manifest": manifest,
        ]
        for (col, max) in fieldMax { v[col] = sliceUTF16(v[col] ?? "", max) }
        return v
    }

    struct Part { let mimePart: String; let size: Int; let inline: Bool }

    nonisolated static func partList(_ p: MailMime.Parsed) -> [Part] {
        p.attachments.map { Part(mimePart: $0.mimePart, size: $0.bytes.count, inline: $0.inline) }
    }

    nonisolated static func spamHeaders(_ p: MailMime.Parsed) -> [String: String] {
        ["x_spam": MailMime.headerValue(p, "X-Spam"), "x_spam_flag": MailMime.headerValue(p, "X-Spam-Flag"),
         "x_spam_score": MailMime.headerValue(p, "X-Spam-Score"), "x_spam_status": MailMime.headerValue(p, "X-Spam-Status")]
    }

    /// An HTML body's readable text (the browser's DOMParser textContent with
    /// script, style and head removed), for search and the preview.
    nonisolated static func readableText(_ html: String) -> String {
        guard !html.isEmpty else { return "" }
        var s = html
        for pattern in ["(?is)<head\\b.*?</head>", "(?is)<script\\b.*?</script>", "(?is)<style\\b.*?</style>", "<[^>]*>"] {
            s = s.replacingOccurrences(of: pattern, with: " ", options: .regularExpression)
        }
        return EmailDigest.decodeEntities(s)
    }

    /// The largest relay-sealed message this phone takes on: the opened raw, its
    /// parse and the sealed copies all sit in memory at once (review F4).
    nonisolated static let maxPendingBytes = 25 * 1024 * 1024
    nonisolated static let skipKey = "joinery.fortress.pending_skip"
    nonisolated static let parsingKey = "joinery.fortress.pending_parsing"

    /// Ids this phone could not parse, kept across drains (and across a crash:
    /// the item being parsed is marked first, so one that took the app down is
    /// skipped the next time). The newest 200; the server's list is bounded.
    nonisolated static var remembered: [Int] {
        get { UserDefaults.standard.array(forKey: skipKey) as? [Int] ?? [] }
        set { UserDefaults.standard.set(Array(newValue.suffix(200)), forKey: skipKey) }
    }

    /// What the banner says once nothing more can be handed to this phone:
    /// the true count still waiting, and why (R6).
    nonisolated static func pendingNote(remaining: Int, tooLarge: Int, skipped: Bool) -> String? {
        func n(_ k: Int) -> String { "\(k) new end-to-end message\(k == 1 ? "" : "s")" }
        var lines: [String] = []
        if tooLarge > 0 {
            lines.append("\(n(tooLarge)) \(tooLarge == 1 ? "is" : "are") too large to open on this phone; open \(tooLarge == 1 ? "it" : "them") on a computer.")
        }
        let rest = remaining - tooLarge
        if rest > 0 {
            lines.append(skipped
                ? "\(n(rest)) could not be opened on this phone. Another of your devices may open \(rest == 1 ? "it" : "them")."
                : "\(n(rest)) waiting to be opened.")
        }
        return lines.isEmpty ? nil : lines.joined(separator: " ")
    }

    /// Parse and store every relay-sealed message waiting for this key. The count stored.
    func drainPending(mailboxes: [Mailbox]) async -> Int {
        let epoch0 = epoch
        var stored = 0
        if let crashed = UserDefaults.standard.object(forKey: Self.parsingKey) as? Int {
            Self.remembered = Self.remembered + [crashed]
            UserDefaults.standard.removeObject(forKey: Self.parsingKey)
        }
        var skip: [Int] = Self.remembered
        var refetched = Set<Int>()
        var rules: [Int: [MailRuleMatch.Rule]] = [:]
        defer { if pendingLine?.contains("being opened") == true { pendingLine = nil } }
        while fortress.isOpen && epoch == epoch0 {
            guard let d = try? await api.action("mailbox/fortress_pending", [
                (key: "skip", value: .string(skip.map(String.init).joined(separator: ","))),
                (key: "max_bytes", value: .number(Double(Self.maxPendingBytes))),
            ]) else { break }
            let remaining = d["remaining"]?.intValue ?? 0
            guard let item = d["item"], !item.isNull, let id = item["id"]?.intValue else {
                pendingLine = Self.pendingNote(remaining: remaining, tooLarge: d["too_large"]?.intValue ?? 0,
                                               skipped: !skip.isEmpty)
                break
            }
            pendingLine = "\(remaining) new end-to-end message\(remaining == 1 ? "" : "s") being opened on this phone."
            do {
                let alias = item["alias_id"]?.intValue
                if let alias, rules[alias] == nil {
                    let r = try? await api.action("mailbox/device_rules", [(key: "alias_id", value: .number(Double(alias)))])
                    rules[alias] = (r?["rules"]?.arrayValue ?? []).compactMap { MailRuleMatch.Rule(json: $0) }
                }
                UserDefaults.standard.set(id, forKey: Self.parsingKey)
                defer { UserDefaults.standard.removeObject(forKey: Self.parsingKey) }
                let outcome = try await parsePending(item, rules: alias.flatMap { rules[$0] } ?? [], epoch0: epoch0)
                switch outcome {
                case "dropped": return stored
                case "stale":
                    if refetched.contains(id) { skip.append(id); Self.remembered = Self.remembered + [id] }
                    refetched.insert(id)
                case "stored": stored += 1
                default: break
                }
            } catch {
                skip.append(id)
                Self.remembered = Self.remembered + [id]
            }
        }
        return stored
    }

    /// One pending row: open under the row's DEK, parse, evaluate the rules,
    /// seal every field and part under the same DEK, post the lot.
    func parsePending(_ item: JSONValue, rules: [MailRuleMatch.Rule], epoch0: Int) async throws -> String {
        guard let id = item["id"]?.intValue, let sealedDek = item["sealed_dek"]?.stringValue,
              let sealedRaw = item["sealed_raw"]?.stringValue, let rawAD = item["raw_ad"]?.stringValue else {
            throw FortressError.message("A pending message is missing its parts.")
        }
        let key = try fortress.rowKey(messageID: id, sealedDek: sealedDek)
        var raw = try VaultCrypto.openEdgeBytes(sealedRaw, key: key, ad: rawAD)
        defer { raw.wipe() }
        let p = MailMime.parse(raw)
        let prefix = (item["sealed_ad_prefix"]?.stringValue ?? "mail:") + String(id)

        let readable = Self.readableText(p.textHTML)
        var values = Self.storeValues(p, snippetFallback: readable)
        let names = p.attachments.map(\.filename).filter { !$0.isEmpty }
        let snippet = FortressSession.snippet(p.textPlain)
        values["iem_snippet"] = snippet.isEmpty
            ? String(readable.split(whereSeparator: { $0.isWhitespace }).joined(separator: " ").unicodeScalars.prefix(240).map(Character.init))
            : snippet
        values["iem_search_text"] = Gzip.packSearchText([p.from, p.subject, names.joined(separator: " "), p.textPlain, readable]
            .joined(separator: " "))

        // The mailbox's rules, on the plaintext only this device has (§ R14).
        let recipient = item["recipient"]?.stringValue ?? ""
        let size = item["size_bytes"]?.intValue ?? 0
        let msg = MailRuleMatch.Message(sender: p.from, recipient: recipient.isEmpty ? p.to : recipient, subject: p.subject,
                                        bodyPlain: p.textPlain, bodyHTML: p.textHTML,
                                        sizeBytes: size > 0 ? size : raw.count, hasAttachment: !p.attachments.isEmpty)
        let matched = MailRuleMatch.matchingIDs(rules, msg)
        let forwards = rules.contains { matched.contains($0.id) && $0.forwards }

        var fields: [(key: String, value: JSONValue)] = []
        for col in ["iem_sender", "iem_subject", "iem_body_plain", "iem_body_html", "iem_raw_headers", "iem_to", "iem_cc",
                    "iem_snippet", "iem_search_text", "iem_attachment_manifest"] {
            fields.append((col, .string(try VaultCrypto.sealField(values[col] ?? "", key: key, ad: "\(prefix):\(col)"))))
        }
        // Every part in ONE upload, each at its offset (the server takes at
        // most 20 files per request; a message can have more parts).
        var bundle = Data()
        var parts: [JSONValue] = []
        for a in p.attachments {
            let sealed = Data(try VaultCrypto.sealEdgeBytes(a.bytes, key: key, ad: "\(prefix):att:\(a.mimePart)").utf8)
            parts.append(.object([(key: "mime_part", value: .string(a.mimePart)), (key: "size", value: .number(Double(a.bytes.count))),
                                  (key: "inline", value: .bool(a.inline)), (key: "offset", value: .number(Double(bundle.count))),
                                  (key: "length", value: .number(Double(sealed.count)))]))
            bundle.append(sealed)
        }
        let spam = Self.spamHeaders(p)
        let text: [(key: String, value: String)] = [
            (key: "id", value: String(id)),
            (key: "sealed_dek", value: sealedDek),
            (key: "fields", value: JSONValue.object(fields).encoded()),
            (key: "parts", value: JSONValue.array(parts).encoded()),
            (key: "spam_headers", value: JSONValue.object(["x_spam", "x_spam_flag", "x_spam_score", "x_spam_status"]
                .map { (key: $0, value: .string(spam[$0] ?? "")) }).encoded()),
            (key: "rule_matches", value: JSONValue.array(matched.map { .number(Double($0)) }).encoded()),
        ]
        var files: [MultipartFile] = []
        if !bundle.isEmpty {
            files.append(MultipartFile(field: "bundle", filename: "parts", mimeType: "application/octet-stream", data: bundle))
        }
        if forwards {
            files.append(MultipartFile(field: "forward_raw", filename: "message.eml", mimeType: "message/rfc822", data: raw))
        }
        guard epoch == epoch0, fortress.isOpen else { return "dropped" }
        let answer = try await api.multipart("mailbox/fortress_parse_store", text, files: files)
        if answer["stored"]?.boolValue == true { return "stored" }
        return answer["stale"]?.boolValue == true ? "stale" : "already"
    }

    // MARK: The relay pin (§ R7) — verify and alarm only

    static let sealTargetPrefix = "joinery-relay:seal-target:v1\n"
    static let pinPrefix = "joinery-relay-pin:v1\n"

    nonisolated static func pinMessage(_ aliasID: Int, _ identity: String) -> Data {
        Data((pinPrefix + "\(aliasID)\n" + identity).utf8)
    }

    enum PinVerdict: Equatable {
        case ok(firstUse: Bool, identity: String)
        case bad(reason: String, expected: String, reported: String)
    }

    /// The browser's judgeSealTarget: the pin must be this vault's MAC, the
    /// statement signed by the pinned relay, naming this mailbox and this key.
    nonisolated static func judge(answer: JSONValue, aliasID: Int, address: String, secret: Data, keys: [String]) -> PinVerdict {
        guard let relayText = answer["relay_answer"]?.stringValue,
              let relay = try? JSONValue.parse(relayText), let statement = relay["statement"]?.stringValue,
              let st = try? JSONValue.parse(statement), let signature = relay["signature"]?.stringValue else {
            return .bad(reason: "unreadable", expected: "", reported: "")
        }
        let reportedID = st["relay_identity_public_key"]?.stringValue ?? ""
        var pinned: String
        var firstUse = false
        if let pin = answer["pin"], !pin.isNull {
            let pinID = pin["relay_identity_public_key"]?.stringValue ?? ""
            let mine = VaultCrypto.sameText(VaultCrypto.b64(VaultCrypto.mac(secret: secret, info: "sealed-vault:pin",
                                                                            message: pinMessage(aliasID, pinID))),
                                            pin["mac"]?.stringValue ?? "")
            if !mine { return .bad(reason: "pin", expected: pinID, reported: reportedID) }
            pinned = pinID
        } else {
            pinned = answer["relay_identity_public_key"]?.stringValue ?? ""
            firstUse = true
        }
        let signed = Data((sealTargetPrefix + statement).utf8)
        let names = st["recipient"]?.stringValue == address.lowercased() && st["key_scope"]?.stringValue == "mail"
            && st["key_kind"]?.stringValue == "client" && keys.contains(st["public_key"]?.stringValue ?? "")
        if !VaultCrypto.sameText(reportedID, pinned) {
            return .bad(reason: "identity", expected: pinned, reported: reportedID)
        }
        if !VaultCrypto.verifyEd25519(publicKeyB64: pinned, message: signed, signatureB64: signature) {
            return .bad(reason: "signature", expected: pinned, reported: pinned)
        }
        if !names { return .bad(reason: "key", expected: keys.first ?? "", reported: st["public_key"]?.stringValue ?? "") }
        return .ok(firstUse: firstUse, identity: pinned)
    }

    static let alarmReasons = [
        "pin": "The relay this mailbox trusts was changed without this device.",
        "identity": "A different relay is answering for this mailbox than the one this device trusts.",
        "signature": "The relay's answer is not signed by the relay this device trusts.",
        "key": "The relay is sealing this mailbox's mail to a key that is not this device's.",
        "unreadable": "The relay's answer could not be read.",
    ]

    /// Every own Fortress mailbox fronted by the relay, once per app run. A
    /// mismatch raises the alarm; trusting a new relay is a computer's step-up.
    func checkRelayPins(mailboxes: [Mailbox]) async {
        guard !pinsChecked, let secret = fortress.vault.heldSecret else { return }
        let boxes = mailboxes.filter { $0.isFortress && $0.own && $0.protectionAddons.contains("Seal at the relay") }
        guard !boxes.isEmpty else { return }
        // Mid-rotation the pending key opens only through the root vault, which
        // a phone never holds: the check waits for the rotation to finish.
        guard let probe = try? await fortress.vault.probe(), probe.pendingPublicKey == nil else { return }
        guard let derived = try? VaultCrypto.publicKey(fromSecret: secret) else { return }
        pinsChecked = true
        for box in boxes {
            guard var answer = try? await api.action("mailbox/relay_seal_target",
                                                     [(key: "alias_id", value: .number(Double(box.aliasID)))]) else { continue }
            let localKey = "joinery.relaypin.\(box.aliasID)"
            if (answer["pin"] ?? .null).isNull, let local = UserDefaults.standard.string(forKey: localKey),
               case .object(var pairs) = answer {
                pairs.removeAll { $0.key == "relay_identity_public_key" }
                pairs.append((key: "relay_identity_public_key", value: .string(local)))
                answer = .object(pairs)
            }
            switch Self.judge(answer: answer, aliasID: box.aliasID, address: box.address, secret: secret, keys: [derived]) {
            case .ok(_, let identity):
                UserDefaults.standard.set(identity, forKey: localKey)
                // A first use is only remembered here: pinning, and trusting a
                // new relay, are a computer's (R11).
            case .bad(let reason, let expected, let reported):
                relayAlarm = RelayAlarm(address: box.address,
                                        reason: (Self.alarmReasons[reason] ?? Self.alarmReasons["unreadable"]!)
                                            + " Until this is settled, mail to this address may be readable by whoever runs this server. Approve a new relay from a computer.",
                                        expected: VaultCrypto.fingerprint(expected), reported: VaultCrypto.fingerprint(reported))
            }
        }
    }

    // MARK: Rules on existing mail (§ R14)

    /// `rule_backlog` → evaluate → `rule_outcomes`, until no rule has a backlog.
    /// Never forwards (Gmail does not re-forward historical mail).
    func ruleBacklog() async {
        let epoch0 = epoch
        var lastThrough: [Int: Int] = [:]
        while fortress.isOpen && epoch == epoch0 {
            guard let d = try? await api.action("mailbox/rule_backlog", []),
                  let rj = d["rule"], !rj.isNull, let rule = MailRuleMatch.Rule(json: rj),
                  let through = d["through_id"]?.intValue else { return }
            if lastThrough[rule.id] == through { return }   // no progress: stop, try next visit
            lastThrough[rule.id] = through
            var matched: [JSONValue] = []
            for row in d["rows"]?.arrayValue ?? [] {
                guard let id = row["id"]?.intValue, let sealed = SealedRow(json: row["sealed"]) else { continue }
                guard let o = try? fortress.open(sealed) else { continue }
                let recipient = row["recipient"]?.stringValue ?? ""
                let m = MailRuleMatch.Message(sender: o["iem_sender"] ?? "",
                                              recipient: recipient.isEmpty ? (o["iem_recipient"] ?? "") : recipient,
                                              subject: o["iem_subject"] ?? "", bodyPlain: o["iem_body_plain"] ?? "",
                                              bodyHTML: o["iem_body_html"] ?? "", sizeBytes: row["size_bytes"]?.intValue ?? 0,
                                              hasAttachment: row["has_attachment"]?.boolValue ?? false)
                if MailRuleMatch.matches(rule, m) { matched.append(.number(Double(id))) }
            }
            guard epoch == epoch0 else { return }
            _ = try? await api.action("mailbox/rule_outcomes", [
                (key: "rule_id", value: .number(Double(rule.id))),
                (key: "through_id", value: .number(Double(through))),
                (key: "matched_ids", value: .array(matched)),
            ])
        }
    }

    // MARK: AI on the person's own model (§ R13)

    public static let drainCap = 200

    /// Every device recipe's queue on each Fortress mailbox, judged on the
    /// person's own model. Stops, with the model's own words, when it does not
    /// answer usefully. The count judged.
    func aiDrain(mailboxes: [Mailbox]) async -> Int {
        guard ai.callURL != nil else { return 0 }
        if ai.wifiOnly && !ai.onWiFi {
            aiLine = "AI waits for Wi-Fi (your setting)."
            return 0
        }
        let epoch0 = epoch
        var count = 0
        for box in mailboxes where box.isFortress && box.own {
            guard let info = try? await api.action("mailbox/ai_device_recipes",
                                                   [(key: "mailbox", value: .string(box.address))]) else { continue }
            if let refusal = info["consent_refusal"]?.stringValue, !refusal.isEmpty { aiLine = refusal; continue }
            // The model's origin is registered with the account on a computer
            // (a step-up); the phone's address must be on it.
            let origin = info["device_ai_origin"]?.stringValue ?? ""
            ai.registeredOrigin = origin.isEmpty ? nil : origin
            if origin.isEmpty {
                aiLine = "Register where your AI model lives on the Email settings page on a computer."
                return count
            }
            guard let endpoint = ai.endpoint(held: fortress.session.heldKeys) else {
                aiLine = "Your model's address is not on \(origin), the one registered for your account. Change it in Mail settings."
                return count
            }
            for recipeJSON in info["recipes"]?.arrayValue ?? [] {
                var recipe = DeviceAIJudge.Recipe(json: recipeJSON)
                recipe.authservID = info["authserv_id"]?.stringValue ?? ""
                var before = 0
                repeat {
                    guard let page = try? await api.action("mailbox/device_ai_entries", [
                        (key: "recipe_id", value: .number(Double(recipe.recipeID))),
                        (key: "alias_id", value: .number(Double(info["alias_id"]?.intValue ?? box.aliasID))),
                        (key: "before_id", value: .number(Double(before))),
                    ]) else { break }
                    for entry in page["entries"]?.arrayValue ?? [] {
                        if count >= Self.drainCap { aiLine = "\(Self.drainCap) judged; more next time."; return count }
                        guard fortress.isOpen, epoch == epoch0 else { aiLine = "Paused while your mail is locked."; return count }
                        aiLine = "Judging your mail with \(endpoint.model)… \(count) done."
                        let out = await DeviceAIJudge.judge(entry: entry, recipe: recipe, endpoint: endpoint,
                                                            deps: .live(fortress: fortress, api: api))
                        switch out.status {
                        case "dropped": aiLine = "Paused while your mail is locked."; return count
                        case "skip": continue   // not openable here: left for another device
                        case "stop":
                            aiLine = "Your model did not answer" + (out.http.map { " (\($0))" } ?? "") + ": "
                                + (out.reason == "unreachable" ? "it could not be reached." : (out.reason ?? "")) + " AI waits until it does."
                            return count
                        default: count += 1
                        }
                    }
                    before = page["next_before_id"]?.intValue ?? 0
                } while before > 0
            }
        }
        aiLine = count > 0 ? "Up to date. \(count) judged on this phone." : nil
        return count
    }
}

/// One AI judgement — the browser's `MailboxFortress.judgeEntry()`.
public enum DeviceAIJudge {
    public struct Recipe {
        public var recipeID: Int
        public var jobID: String
        public var system: String
        public var nonce: String
        public var maxTokens: Int
        public var reasoningEffort: String
        public var attachments: Bool
        public var authservID: String
        public var descriptor: JSONValue?

        public init(json: JSONValue) {
            recipeID = json["recipe_id"]?.intValue ?? 0
            jobID = json["job_id"]?.stringValue ?? ""
            system = json["system"]?.stringValue ?? ""
            nonce = json["nonce"]?.stringValue ?? ""
            maxTokens = json["max_tokens"]?.intValue ?? 400
            let e = json["reasoning_effort"]?.stringValue ?? ""
            reasoningEffort = e.isEmpty ? "none" : e
            attachments = json["attachments"]?.boolValue ?? false
            authservID = json["authserv_id"]?.stringValue ?? ""
            descriptor = json["verdict_descriptor"]
        }
    }

    public struct Endpoint: Equatable {
        public let url: String
        public let key: String
        public let model: String
        public init(url: String, key: String, model: String) { self.url = url; self.key = key; self.model = model }
    }

    public struct Outcome {
        public var status: String
        public var field: String?
        public var plaintext: String?
        public var model: String?
        public var reason: String?
        public var http: Int?
    }

    /// What a judgement reaches the world through (tests hand in stand-ins).
    public struct Deps {
        public var open: (JSONValue) async throws -> [String: String]
        public var key: (JSONValue) async throws -> SymmetricKey
        public var fetch: (URLRequest) async throws -> (Int, Data)
        public var post: (String, [(key: String, value: JSONValue)]) async throws -> JSONValue
        public var epoch: () -> Int

        @MainActor
        static func live(fortress: FortressSession, api: MailAPI) -> Deps {
            Deps(
                open: { entry in
                    guard let row = SealedRow(json: entry["sealed"]) else { throw FortressError.locked }
                    return try await MainActor.run { try fortress.open(row) }
                },
                key: { entry in
                    let id = entry["id"]?.intValue ?? 0
                    let dek = entry["sealed"]?["sealed_dek"]?.stringValue ?? ""
                    return try await MainActor.run { try fortress.rowKey(messageID: id, sealedDek: dek) }
                },
                fetch: { req in
                    let (data, resp) = try await DeviceAISettings.session.data(for: req)
                    return ((resp as? HTTPURLResponse)?.statusCode ?? 0, data)
                },
                post: { action, fields in try await api.action(action, fields) },
                epoch: { MainActor.assumeIsolated { fortress.lockEpoch } })
        }
    }

    static func messageJSON(_ role: String, _ content: String) -> JSONValue {
        .object([(key: "role", value: .string(role)), (key: "content", value: .string(content))])
    }

    public static func requestBody(recipe: Recipe, endpoint: Endpoint, messages: [JSONValue], effort: String) -> JSONValue {
        .object([
            (key: "model", value: .string(endpoint.model)),
            (key: "messages", value: .array(messages)),
            (key: "max_tokens", value: .number(Double(recipe.maxTokens))),
            (key: "reasoning_effort", value: .string(effort)),
            (key: "response_format", value: .object([(key: "type", value: .string("json_object"))])),
            (key: "stream", value: .bool(false)),
        ])
    }

    /// On the main actor: the lock epoch it checks lives there.
    @MainActor
    public static func judge(entry: JSONValue, recipe: Recipe, endpoint: Endpoint, deps: Deps) async -> Outcome {
        let at = deps.epoch()
        let o: [String: String]
        do { o = try await deps.open(entry) } catch {
            // A lock is a drop; one message this device cannot open is skipped
            // and nothing is recorded, so another device can still judge it.
            return Outcome(status: deps.epoch() != at ? "dropped" : "skip")
        }
        var input = EmailDigest.Input()
        let raw = o["iem_raw_headers"] ?? ""
        input.raw = raw.isEmpty ? nil : raw
        input.sender = o["iem_sender"] ?? ""
        let rcpt = o["iem_recipient"] ?? ""
        input.recipient = rcpt.isEmpty ? (entry["recipient"]?.stringValue ?? "") : rcpt
        input.receivedTime = entry["received_time"]?.stringValue ?? ""
        input.subject = o["iem_subject"] ?? ""
        input.bodyPlain = o["iem_body_plain"] ?? ""
        input.bodyHTML = o["iem_body_html"] ?? ""
        input.spf = entry["spf_result"]?.stringValue ?? ""
        input.dkim = entry["dkim_result"]?.stringValue ?? ""
        input.dmarc = entry["dmarc_result"]?.stringValue ?? ""
        input.authservID = recipe.authservID
        var digest = EmailDigest.build(input)
        if recipe.attachments {
            let att = EmailDigest.attachments(FortressSession.manifest(o["iem_attachment_manifest"]))
            if !att.isEmpty { digest += "\n\n" + att }
        }
        var messages = [messageJSON("system", recipe.system),
                        messageJSON("user", EmailDigest.wrapBlock(digest, nonce: recipe.nonce))]
        var verdict: JSONValue?
        var served = endpoint.model
        var effort = recipe.reasoningEffort
        for attempt in 1...2 {
            if deps.epoch() != at { return Outcome(status: "dropped") }
            guard let url = URL(string: endpoint.url) else { return Outcome(status: "stop", reason: "unreachable") }
            var req = URLRequest(url: url)
            req.httpMethod = "POST"
            req.timeoutInterval = 120
            req.setValue("application/json", forHTTPHeaderField: "Content-Type")
            if !endpoint.key.isEmpty { req.setValue("Bearer " + endpoint.key, forHTTPHeaderField: "Authorization") }
            req.httpBody = requestBody(recipe: recipe, endpoint: endpoint, messages: messages, effort: effort).encodedData()
            let status: Int, data: Data
            do { (status, data) = try await deps.fetch(req) } catch { return Outcome(status: "stop", reason: "unreachable") }
            let body = try? JSONValue.parse(data)
            if status < 200 || status >= 300 {
                var reason = String(decoding: data.prefix(200), as: UTF8.self)
                if let err = body?["error"], !err.isNull {
                    reason = err.stringValue ?? err["message"]?.stringValue ?? err.encoded()
                }
                return Outcome(status: "stop", reason: reason, http: status)
            }
            if let m = body?["model"]?.stringValue { served = m }
            let choice = body?["choices"]?.arrayValue?.first
            let content = choice?["message"]?["content"]?.stringValue ?? ""
            if content.isEmpty && choice?["finish_reason"]?.stringValue == "length" && effort != "none" {
                effort = "none"
                continue
            }
            switch VerdictCheck.parse(content, descriptor: recipe.descriptor, jobID: recipe.jobID) {
            case .verdict(let v):
                verdict = v
            case .error(let error):
                if attempt < 2 {
                    messages.append(messageJSON("assistant", content))
                    messages.append(messageJSON("user", VerdictCheck.retryMessage(error)))
                }
            }
            if verdict != nil { break }
        }
        if deps.epoch() != at { return Outcome(status: "dropped") }
        guard let verdict else {
            _ = try? await deps.post("mailbox/ai_device_record", [(key: "recipe_id", value: .number(Double(recipe.recipeID))),
                                                                  (key: "item_key", value: .string(String(entry["id"]?.intValue ?? 0)))])
            return Outcome(status: "error")
        }
        let field: String, plaintext: String
        var score: JSONValue? = nil
        if recipe.jobID == "email_security_scan" {
            field = "iem_ai_scan"
            plaintext = JSONValue.object([
                (key: "verdict", value: verdict["verdict"] ?? .string("")),
                (key: "red_flags", value: verdict["red_flags"] ?? .array([])),
                (key: "summary", value: verdict["summary"] ?? .string("")),
                (key: "model", value: .string(served)),
                (key: "recipe_id", value: .number(Double(recipe.recipeID))),
            ]).encoded()
            score = verdict["score"]
        } else {
            field = "iem_ai_summary"
            plaintext = verdict["summary"]?.stringValue ?? ""
        }
        guard let key = try? await deps.key(entry) else { return Outcome(status: deps.epoch() != at ? "dropped" : "skip") }
        guard deps.epoch() == at else { return Outcome(status: "dropped") }
        let id = entry["id"]?.intValue ?? 0
        let prefix = entry["sealed"]?["sealed_ad_prefix"]?.stringValue ?? "mail:"
        guard let sealed = try? VaultCrypto.sealField(plaintext, key: key, ad: "\(prefix)\(id):\(field)"),
              deps.epoch() == at else { return Outcome(status: "dropped") }
        var payload: [(key: String, value: JSONValue)] = [
            (key: "id", value: .number(Double(id))), (key: "recipe_id", value: .number(Double(recipe.recipeID))),
            (key: "fields", value: .object([(key: field, value: .string(sealed))])),
        ]
        if let score { payload.append((key: "danger_score", value: score)) }
        _ = try? await deps.post("mailbox/device_ai_verdict", payload)
        return Outcome(status: "done", field: field, plaintext: plaintext, model: served)
    }
}

/// Where the person's own model lives, as this phone knows it (§ R13): the
/// rest of the address, the model name and the Wi-Fi-only switch in the
/// app's defaults; the API key behind the biometric gate beside the mail
/// secret, opened with it. The origin is registered with the account under a
/// step-up on a computer; the phone never registers one.
@MainActor
public final class DeviceAISettings: ObservableObject {
    public static let keyName = "deviceai.key"
    static let session: URLSession = {
        let c = URLSessionConfiguration.ephemeral
        c.httpCookieStorage = nil
        c.httpShouldSetCookies = false
        c.timeoutIntervalForRequest = 120
        return URLSession(configuration: c)
    }()

    @Published public var baseURL: String { didSet { save() } }
    @Published public var model: String { didSet { save() } }
    @Published public var wifiOnly: Bool { didSet { save() } }
    @Published public private(set) var onWiFi = true
    private let deviceKeys: DeviceKeyStore
    private let monitor = NWPathMonitor()
    private let prefix: String

    public init(deviceKeys: DeviceKeyStore, userID: Int) {
        self.deviceKeys = deviceKeys
        prefix = "joinery.deviceai.\(userID)."
        let d = UserDefaults.standard
        baseURL = d.string(forKey: prefix + "base") ?? ""
        model = d.string(forKey: prefix + "model") ?? ""
        wifiOnly = d.object(forKey: prefix + "wifi_only") as? Bool ?? true
        monitor.pathUpdateHandler = { [weak self] path in
            let wifi = !path.usesInterfaceType(.cellular)
            Task { @MainActor in self?.onWiFi = wifi }
        }
        monitor.start(queue: DispatchQueue(label: "joinery.deviceai.path"))
    }

    private func save() {
        let d = UserDefaults.standard
        d.set(baseURL, forKey: prefix + "base")
        d.set(model, forKey: prefix + "model")
        d.set(wifiOnly, forKey: prefix + "wifi_only")
    }

    /// Store the API key behind the gate (and hold it now).
    public func setKey(_ key: String, held: HeldKeys) throws {
        if key.isEmpty {
            deviceKeys.forgetSecret(name: Self.keyName)
            held.holdExtra(Data(), name: Self.keyName)
            return
        }
        try deviceKeys.storeSecret(Data(key.utf8), name: Self.keyName)
        held.holdExtra(Data(key.utf8), name: Self.keyName)
    }

    /// Is `url` on `origin` (scheme, host and port)?
    nonisolated public static func sameOrigin(_ url: String, _ origin: String) -> Bool {
        guard let u = URL(string: url), let o = URL(string: origin) else { return false }
        func port(_ x: URL) -> Int { x.port ?? (x.scheme?.lowercased() == "https" ? 443 : 80) }
        return u.scheme?.lowercased() == o.scheme?.lowercased() && u.host?.lowercased() == o.host?.lowercased()
            && port(u) == port(o)
    }

    /// `{base}/chat/completions`, or nil when the base is not an https (or
    /// local http) address.
    public var callURL: String? {
        var b = baseURL.trimmingCharacters(in: .whitespacesAndNewlines)
        while b.hasSuffix("/") { b.removeLast() }
        guard let u = URL(string: b), let scheme = u.scheme?.lowercased(), scheme == "https" || scheme == "http",
              u.host != nil, u.query == nil, u.fragment == nil else { return nil }
        return b + "/chat/completions"
    }

    /// The origin registered with the account on a computer (ai_device_recipes'
    /// `device_ai_origin`), as last read. The phone calls nothing else — and in
    /// particular plain http only there.
    @Published public var registeredOrigin: String?

    public func endpoint(held: HeldKeys) -> DeviceAIJudge.Endpoint? {
        guard let url = callURL, !model.trimmingCharacters(in: .whitespaces).isEmpty,
              let origin = registeredOrigin, Self.sameOrigin(url, origin) else { return nil }
        let key = held.extra(Self.keyName).map { String(decoding: $0, as: UTF8.self) } ?? ""
        return DeviceAIJudge.Endpoint(url: url, key: key, model: model.trimmingCharacters(in: .whitespaces))
    }

    /// Test: the real system prompt and a made-up digest (device_ai_test_prompt)
    /// sent as a judgement would be; says which step failed.
    public func test(api: MailAPI, held: HeldKeys, mailbox: String?) async -> String {
        if let mailbox, let info = try? await api.action("mailbox/ai_device_recipes", [(key: "mailbox", value: .string(mailbox))]) {
            let o = info["device_ai_origin"]?.stringValue ?? ""
            registeredOrigin = o.isEmpty ? nil : o
        }
        guard registeredOrigin != nil else {
            return "Register where your AI model lives on the Email settings page on a computer first."
        }
        guard callURL != nil, !model.trimmingCharacters(in: .whitespaces).isEmpty else {
            return "Enter your model's address and name first."
        }
        guard let ep = endpoint(held: held) else {
            return "Your model's address must be on \(registeredOrigin!), the one registered for your account."
        }
        guard let prompt = try? await api.action("mailbox/device_ai_test_prompt", []) else {
            return "The test prompt could not be fetched from your site."
        }
        var messages: [JSONValue] = []
        if let sys = prompt["system"]?.stringValue { messages.append(DeviceAIJudge.messageJSON("system", sys)) }
        if let user = prompt["user"]?.stringValue {
            messages.append(DeviceAIJudge.messageJSON("user", user))
        }
        var req = URLRequest(url: URL(string: ep.url)!)
        req.httpMethod = "POST"
        req.setValue("application/json", forHTTPHeaderField: "Content-Type")
        if !ep.key.isEmpty { req.setValue("Bearer " + ep.key, forHTTPHeaderField: "Authorization") }
        req.httpBody = JSONValue.object([
            (key: "model", value: .string(ep.model)), (key: "messages", value: .array(messages)),
            (key: "max_tokens", value: .number(Double(prompt["max_tokens"]?.intValue ?? 1024))),
            (key: "reasoning_effort", value: .string("none")),
            (key: "response_format", value: .object([(key: "type", value: .string("json_object"))])),
            (key: "stream", value: .bool(false)),
        ]).encodedData()
        do {
            let (data, resp) = try await Self.session.data(for: req)
            let status = (resp as? HTTPURLResponse)?.statusCode ?? 0
            let body = try? JSONValue.parse(data)
            if status == 401 || status == 403 { return "Your model refused the key (\(status))." }
            if status == 404 { return "Your model's address answered 404: check the rest of the address and the model name." }
            if status < 200 || status >= 300 {
                return "Your model answered \(status): " + (body?["error"]?["message"]?.stringValue ?? body?["error"]?.stringValue ?? "")
            }
            let content = body?["choices"]?.arrayValue?.first?["message"]?["content"]?.stringValue ?? ""
            return content.isEmpty ? "Your model answered, but with nothing. Try a larger context." : "Your model answered. AI is ready."
        } catch {
            return "Your model could not be reached from this phone (\(error.localizedDescription)). Is it on this network or your tailnet?"
        }
    }
}
