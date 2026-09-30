import XCTest
import CryptoKit
@testable import JoineryMailKit
@testable import JoineryKit

/// The phone's ports against the vectors the browser and PHP suites write
/// (specs/fortress_mobile_apps.md § WP1, WP5, WP7, WP9, WP10). Fixtures/vectors/
/// holds verbatim copies; Tests/sync_vectors.sh --check proves they are current.
final class PortVectorTests: XCTestCase {

    static func vector(_ name: String) throws -> JSONValue {
        let url = try XCTUnwrap(
            Bundle.module.url(forResource: "Fixtures/vectors/\(name)", withExtension: nil)
                ?? Bundle.module.url(forResource: name, withExtension: nil, subdirectory: "Fixtures/vectors"),
            "missing vector \(name)")
        return try JSONValue.parse(Data(contentsOf: url))
    }

    static func data(_ name: String) throws -> Data {
        let url = try XCTUnwrap(
            Bundle.module.url(forResource: "Fixtures/vectors/\(name)", withExtension: nil)
                ?? Bundle.module.url(forResource: name, withExtension: nil, subdirectory: "Fixtures/vectors"),
            "missing vector \(name)")
        return try Data(contentsOf: url)
    }

    static func hex(_ b: [UInt8]) -> String { b.map { String(format: "%02x", $0) }.joined() }
    static func unhex(_ s: String) -> [UInt8] {
        var out: [UInt8] = []
        var i = s.startIndex
        while i < s.endIndex {
            let j = s.index(i, offsetBy: 2)
            out.append(UInt8(s[i..<j], radix: 16)!)
            i = j
        }
        return out
    }
    static func ints(_ v: JSONValue?) -> [Int] { (v?.arrayValue ?? []).compactMap(\.intValue) }
    static func strs(_ v: JSONValue?) -> [String] { (v?.arrayValue ?? []).compactMap(\.stringValue) }

    // MARK: Search core

    final class MemStore: MailSearchCore.ShardStore {
        var shards: [Int: [UInt8]] = [:]
        func get(_ shard: Int) throws -> [UInt8]? { shards[shard] }
        func put(_ shard: Int, _ bytes: [UInt8]) throws { shards[shard] = bytes }
    }

    func testTokenizerMatchesTheBrowser() throws {
        for c in try Self.vector("search_core_vectors.json")["tokenizer"]?.arrayValue ?? [] {
            let text = c["text"]?.stringValue ?? ""
            XCTAssertEqual(MailSearchCore.words(text), Self.strs(c["words"]), "words: \(text)")
            XCTAssertEqual(MailSearchCore.queryWords(text), Self.strs(c["query_words"]), "query words: \(text)")
        }
    }

    func testFnvShardsPostingsExtendIds() throws {
        let v = try Self.vector("search_core_vectors.json")
        for c in v["fnv1a"]?.arrayValue ?? [] {
            let b = Self.unhex(c["utf8_hex"]?.stringValue ?? "")
            XCTAssertEqual(Array((c["word"]?.stringValue ?? "").utf8), b)
            XCTAssertEqual(Int(MailSearchCore.fnv1a(b)), c["fnv1a"]?.intValue)
            XCTAssertEqual(MailSearchCore.shardOf(b), c["shard"]?.intValue)
        }
        for c in v["postings"]?.arrayValue ?? [] {
            let docs = Self.ints(c["docs"])
            let p = MailSearchCore.posting(docs)
            XCTAssertEqual(Int(p.kind), c["kind_bit"]?.intValue, "kind for \(docs)")
            XCTAssertEqual(Self.hex(p.bytes), c["bytes_hex"]?.stringValue)
            XCTAssertEqual(MailSearchCore.countOf(p), c["count"]?.intValue)
            XCTAssertEqual(MailSearchCore.lastOf(p), c["last"]?.intValue)
            XCTAssertEqual(MailSearchCore.docsOf(p), docs)
        }
        for c in v["extend"]?.arrayValue ?? [] {
            let p = MailSearchCore.extend(MailSearchCore.posting(Self.ints(c["docs"])), Self.ints(c["more"]))
            XCTAssertEqual(Int(p.kind), c["kind_bit"]?.intValue)
            XCTAssertEqual(Self.hex(p.bytes), c["bytes_hex"]?.stringValue)
            XCTAssertEqual(MailSearchCore.docsOf(p), Self.ints(c["docs_after"]))
        }
        for c in v["ids"]?.arrayValue ?? [] {
            let ids = Self.ints(c["ids"])
            XCTAssertEqual(Self.hex(MailSearchCore.encodeIds(ids)), c["encoded_hex"]?.stringValue)
            XCTAssertEqual(try MailSearchCore.decodeIds(Self.unhex(c["encoded_hex"]?.stringValue ?? "")), ids)
        }
        let pk = try XCTUnwrap(v["pack_ids"])
        XCTAssertEqual(MailSearchCore.packIds(Self.ints(pk["ids"])), pk["packed"]?.stringValue)
        // thread_list's own decoder vector (device_hits_vector.json) too.
        let dh = try Self.vector("device_hits_vector.json")
        XCTAssertEqual(MailSearchCore.packIds(Self.ints(dh["ids"])), dh["packed"]?.stringValue)
    }

    private func messages(_ b: JSONValue?) -> [(Int, String)] {
        (b?["messages"]?.arrayValue ?? []).map { ($0["id"]?.intValue ?? 0, $0["text"]?.stringValue ?? "") }
    }

    func testABuildWritesTheBrowsersShardsByteForByte() throws {
        let v = try Self.vector("search_core_vectors.json")
        let b = try XCTUnwrap(v["build"])
        var idx = MailSearchCore.Index()
        for (id, text) in messages(b) { idx.add(id, text) }
        let store = MemStore()
        let keys = try idx.merge(store)
        XCTAssertEqual(idx.ids, Self.ints(b["doc_ids"]))
        XCTAssertEqual(Self.hex(MailSearchCore.encodeIds(idx.ids)), b["ids_hex"]?.stringValue)
        XCTAssertEqual(keys, Self.ints(b["merged_shard_keys"]))
        for (k, hex) in b["shards"]?.objectValue ?? [] {
            XCTAssertEqual(Self.hex(store.shards[Int(k)!] ?? []), hex.stringValue, "shard \(k)")
        }
        XCTAssertEqual(store.shards.count, b["shards"]?.objectValue?.count)
    }

    func testBatchedMergesEqualTheBuildAndQueriesAnswerAlike() throws {
        let v = try Self.vector("search_core_vectors.json")
        let msgs = messages(v["build"])
        let batches = Self.ints(v["schedule"]?["batches"])
        var idx = MailSearchCore.Index()
        let store = MemStore()
        var at = 0
        for (n, size) in batches.enumerated() {
            for (id, text) in msgs[at..<(at + size)] { idx.add(id, text) }
            at += size
            if n < batches.count - 1 { try idx.merge(store) }
        }
        let before = try XCTUnwrap(v["schedule"]?["before_final_merge"])
        for (k, hex) in before["shards"]?.objectValue ?? [] {
            XCTAssertEqual(Self.hex(store.shards[Int(k)!] ?? []), hex.stringValue, "shard \(k) before the final merge")
        }
        XCTAssertEqual(Self.hex(try MailSearchCore.mapToShard(idx.tail)), before["tail_hex"]?.stringValue)
        XCTAssertEqual(idx.tailDocs, before["tail_docs"]?.intValue)
        XCTAssertEqual(Self.hex(MailSearchCore.encodeIds(idx.ids)), before["ids_hex"]?.stringValue)
        // A reopened index (ids + tail read back) answers the same.
        let reopened = MailSearchCore.Index(ids: try MailSearchCore.decodeIds(MailSearchCore.encodeIds(idx.ids)),
                                            tail: try MailSearchCore.shardToMap(try MailSearchCore.mapToShard(idx.tail)))
        for q in v["queries"]?.arrayValue ?? [] {
            let text = q["q"]?.stringValue ?? ""
            let ids = try idx.search(store, text)
            XCTAssertEqual(ids, Self.ints(q["ids"]), "query \(text)")
            XCTAssertEqual(MailSearchCore.packIds(ids), q["packed"]?.stringValue, "packed \(text)")
            XCTAssertEqual(try reopened.search(store, text), ids, "reopened \(text)")
        }
        // The final merge equals the one-shot build, byte for byte.
        try idx.merge(store)
        for (k, hex) in v["build"]?["shards"]?.objectValue ?? [] {
            XCTAssertEqual(Self.hex(store.shards[Int(k)!] ?? []), hex.stringValue, "shard \(k) after all merges")
        }
    }

    func testRecordAdMatchesTheBrowser() throws {
        let r = try XCTUnwrap(try Self.vector("search_core_vectors.json")["records"])
        let ex = try XCTUnwrap(r["ad_example"])
        let store = try FileShardStore(dir: FileManager.default.temporaryDirectory.appendingPathComponent("adcheck"),
                                       key: SymmetricKey(size: .bits256), userID: ex["user_id"]?.intValue ?? 0)
        XCTAssertEqual(store.ad(ex["record"]?.stringValue ?? ""), ex["ad"]?.stringValue)
        XCTAssertEqual(FileShardStore.shardName(10), "shard:0a")
        XCTAssertEqual(FileShardStore.shardName(255), "shard:ff")
        // A record round-trips through gzip and the seal, and refuses another name.
        try store.write("shard:0a", [1, 2, 3, 250])
        XCTAssertEqual(store.read("shard:0a"), [1, 2, 3, 250])
        XCTAssertNil(store.read("shard:0b"))
    }

    // MARK: MIME

    private func check(_ p: MailMime.Parsed, _ exp: JSONValue, _ label: String) {
        let e = exp["parsed"]
        XCTAssertEqual(p.headers, e?["headers"]?.stringValue, "\(label) headers")
        XCTAssertEqual(p.from, e?["from"]?.stringValue, "\(label) from")
        XCTAssertEqual(p.to, e?["to"]?.stringValue, "\(label) to")
        XCTAssertEqual(p.cc, e?["cc"]?.stringValue, "\(label) cc")
        XCTAssertEqual(p.subject, e?["subject"]?.stringValue, "\(label) subject")
        XCTAssertEqual(p.date, e?["date"]?.stringValue, "\(label) date")
        XCTAssertEqual(p.messageID, e?["message_id"]?.stringValue, "\(label) message-id")
        XCTAssertEqual(p.inReplyTo, e?["in_reply_to"]?.stringValue, "\(label) in-reply-to")
        XCTAssertEqual(p.references, e?["references"]?.stringValue, "\(label) references")
        XCTAssertEqual(p.textPlain, e?["text_plain"]?.stringValue, "\(label) text/plain")
        XCTAssertEqual(p.textHTML, e?["text_html"]?.stringValue, "\(label) text/html")
        let atts = e?["attachments"]?.arrayValue ?? []
        XCTAssertEqual(p.attachments.count, atts.count, "\(label) attachment count")
        for (a, x) in zip(p.attachments, atts) {
            XCTAssertEqual(a.mimePart, x["mime_part"]?.stringValue, "\(label) part")
            XCTAssertEqual(a.filename, x["filename"]?.stringValue, "\(label) filename")
            XCTAssertEqual(a.contentType, x["content_type"]?.stringValue, "\(label) type")
            XCTAssertEqual(a.contentID, x["content_id"]?.stringValue, "\(label) cid")
            XCTAssertEqual(a.inline, x["inline"]?.boolValue, "\(label) inline")
            XCTAssertEqual(a.bytes.count, x["size"]?.intValue, "\(label) size")
            XCTAssertEqual(SHA256.hash(data: a.bytes).map { String(format: "%02x", $0) }.joined(),
                           x["sha256_hex"]?.stringValue, "\(label) bytes of \(a.mimePart)")
        }
        for (name, want) in exp["header_values"]?.objectValue ?? [] {
            XCTAssertEqual(MailMime.headerValue(p, name), want.stringValue, "\(label) header \(name)")
        }
        // What the parse store posts, before sealing.
        let store = FortressWork.storeValues(p, snippetFallback: "")
        for (col, want) in exp["store"]?["fields"]?.objectValue ?? [] {
            XCTAssertEqual(store[col], want.stringValue, "\(label) store \(col)")
        }
        let parts = FortressWork.partList(p)
        XCTAssertEqual(parts.map(\.mimePart), (exp["store"]?["parts"]?.arrayValue ?? []).compactMap { $0["mime_part"]?.stringValue })
        let spam = FortressWork.spamHeaders(p)
        for (k, want) in exp["store"]?["spam_headers"]?.objectValue ?? [] {
            XCTAssertEqual(spam[k], want.stringValue, "\(label) spam header \(k)")
        }
        if let snip = exp["store"]?["snippet_from_plain"]?.stringValue, !p.textPlain.isEmpty {
            XCTAssertEqual(FortressSession.snippet(p.textPlain), snip, "\(label) snippet")
        }
    }

    func testMimeFixturesParseAsTheBrowserDoes() throws {
        for name in ["alternative", "bare_lf", "malformed", "nested_inline_attachment", "plain", "qp_latin1",
                     "rfc2047_subject", "rfc2231_filename"] {
            let raw = try Self.data("mime/\(name).eml")
            let exp = try Self.vector("mime/\(name).expected.json")
            XCTAssertEqual(SHA256.hash(data: raw).map { String(format: "%02x", $0) }.joined(),
                           exp["raw_sha256_hex"]?.stringValue, "\(name) raw")
            check(MailMime.parse(raw), exp, name)
        }
    }

    func testMimeBuiltinCasesNeverThrowAndAgree() throws {
        for c in try Self.vector("mime/builtin.expected.json")["cases"]?.arrayValue ?? [] {
            let raw = Data(base64Encoded: c["raw_b64"]?.stringValue ?? "") ?? Data()
            check(MailMime.parse(raw), c, c["label"]?.stringValue ?? "?")
        }
    }

    // MARK: Mail rules

    func testRuleMatcherAgreesWithPHP() throws {
        for c in try Self.vector("filter_match_cases.json")["cases"]?.arrayValue ?? [] {
            let rules = (c["rules"]?.arrayValue ?? []).compactMap { MailRuleMatch.Rule(json: $0) }
            let m = c["message"]
            let msg = MailRuleMatch.Message(
                sender: MailRuleMatch.str(m?["sender"]), recipient: MailRuleMatch.str(m?["recipient"]),
                subject: MailRuleMatch.str(m?["subject"]), bodyPlain: MailRuleMatch.str(m?["body_plain"]),
                bodyHTML: MailRuleMatch.str(m?["body_html"]),
                sizeBytes: m?["size_bytes"].map { MailRuleMatch.intval($0) } ?? 0,
                hasAttachment: m?["has_attachment"] == .bool(true))
            XCTAssertEqual(MailRuleMatch.matchingIDs(rules, msg), Self.ints(c["expected_ids"]),
                           c["name"]?.stringValue ?? "?")
        }
    }

    // MARK: Device AI

    func testDigestsAttachmentsAndEnvelopes() throws {
        let v = try Self.vector("device_ai_vectors.json")
        for d in v["digests"]?.arrayValue ?? [] {
            let i = d["input"]
            var input = EmailDigest.Input()
            let raw = i?["raw"]
            input.raw = (raw == nil || raw!.isNull) ? nil : raw?.stringValue
            input.sender = i?["sender"]?.stringValue ?? ""
            input.recipient = i?["recipient"]?.stringValue ?? ""
            input.receivedTime = i?["received_time"]?.stringValue ?? ""
            input.subject = i?["subject"]?.stringValue ?? ""
            input.bodyPlain = i?["body_plain"]?.stringValue ?? ""
            input.bodyHTML = i?["body_html"]?.stringValue ?? ""
            input.spf = i?["spf_result"]?.stringValue ?? ""
            input.dkim = i?["dkim_result"]?.stringValue ?? ""
            input.dmarc = i?["dmarc_result"]?.stringValue ?? ""
            input.authservID = i?["authserv_id"]?.stringValue ?? ""
            let want = d["digest"]?.stringValue ?? d["output"]?.stringValue ?? ""
            let got = EmailDigest.build(input)
            if got != want { Self.firstDifference(got, want, d["name"]?.stringValue ?? "?") }
            XCTAssertEqual(got, want, "digest \(d["name"]?.stringValue ?? "?")")
        }
        for a in v["attachments"]?.arrayValue ?? [] {
            XCTAssertEqual(EmailDigest.attachments(a["manifest"]?.arrayValue ?? []), a["section"]?.stringValue,
                           "attachments \(a["name"]?.stringValue ?? "?")")
        }
        for e in v["envelopes"]?.arrayValue ?? [] {
            XCTAssertEqual(EmailDigest.wrapBlock(e["text"]?.stringValue ?? "", nonce: e["nonce"]?.stringValue ?? ""),
                           e["wrapped"]?.stringValue, "envelope \(e["name"]?.stringValue ?? "?")")
        }
    }

    static func firstDifference(_ a: String, _ b: String, _ label: String) {
        let x = Array(a.unicodeScalars), y = Array(b.unicodeScalars)
        var i = 0
        while i < min(x.count, y.count) && x[i] == y[i] { i += 1 }
        let ctx = { (s: [Unicode.Scalar]) in EmailDigest.str(s[max(0, i - 40)..<min(s.count, i + 40)]) }
        print("DIGEST DIFF \(label) at \(i): got «\(ctx(x))» want «\(ctx(y))»")
    }

    func testEncodedWordsDecodeAsPHPsIconvDoes() throws {
        let cases = try Self.vector("digest_words_vector.json")["cases"]?.arrayValue ?? []
        XCTAssertGreaterThanOrEqual(cases.count, 40)
        for c in cases {
            let input = c["input"]?.stringValue ?? ""
            let want = Data(base64Encoded: c["expected_b64"]?.stringValue ?? "") ?? Data()
            XCTAssertEqual(Data(EmailDigest.decodeHeaderValue(input).utf8), want,
                           "\(input) → \(String(decoding: want, as: UTF8.self))")
        }
    }

    func testVerdictsAgreeWithTheServersValidator() throws {
        let v = try Self.vector("device_ai_vectors.json")
        for c in v["verdicts"]?.arrayValue ?? [] {
            let job = c["job_id"]?.stringValue ?? ""
            let r = VerdictCheck.parse(c["answer"]?.stringValue ?? "", descriptor: v["descriptors"]?[job], jobID: job)
            if let err = c["result"]?["error"]?.stringValue {
                XCTAssertEqual(r, .error(err), "answer \(c["answer"]?.stringValue ?? "")")
                XCTAssertEqual(VerdictCheck.retryMessage(err), c["retry_message"]?.stringValue)
            } else if case .verdict(let got) = r {
                XCTAssertEqual(got.encoded(), c["result"]?["verdict"]?.encoded(), "answer \(c["answer"]?.stringValue ?? "")")
            } else {
                XCTFail("refused a valid answer: \(c["answer"]?.stringValue ?? "") → \(r)")
            }
        }
    }
}
