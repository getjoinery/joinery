import Foundation
import CryptoKit
import JoineryKit

/// Search over end-to-end (Fortress) mail, on this phone
/// (specs/fortress_mobile_apps.md § R5) — the browser's `mailbox_search.js` and
/// `mailbox_search_worker.js` in one.
///
/// The server cannot read Fortress mail, so the phone keeps its own index,
/// built once and kept up to date from `mailbox/search_entries`: each row's
/// sealed search text is opened with the mail key and its words added to
/// MailSearchCore's index. A search answers with the ids it found, which
/// `thread_list` takes as `device_hits` (with `device_only` when every mailbox
/// in view is end-to-end, so no term reaches the server).
///
/// On disk the records keep the browser's names and sealing: `head` {format,
/// fp} in the clear; `meta`, `ids`, `tail` and `shard:00`…`shard:ff` each
/// gzipped, then AES-256-GCM under the record key with a fresh IV, AD
/// `mailsearch:{user}:{record}:{format}`. The record key is derived from the
/// mail secret (HKDF, info `joinery-mailsearch:record:v1`), so it exists only
/// while the mail key is held, needs no second prompt, and the index reopens
/// after a re-enrollment of the same key; a rotated key's fingerprint differs
/// and the index starts again. Files are complete-protected and never backed up.
@MainActor
public final class DeviceSearch: ObservableObject {
    public struct Status: Equatable {
        public var indexed = 0
        public var total = 0
        public var building = false
        public var failed: String?

        public var line: String? {
            if let failed { return "Search on this phone stopped: \(failed)" }
            if building { return "Indexing mail on this phone: \(indexed.formatted()) of \(max(total, indexed).formatted())" }
            return nil
        }
    }

    struct Cursor: Equatable {
        var time: String
        var id: Int
        init(time: String, id: Int) { self.time = time; self.id = id }
        init?(_ v: JSONValue?) {
            guard let v, !v.isNull, let t = v["time"]?.stringValue else { return nil }
            time = t
            id = v["id"]?.intValue ?? 0
        }
        var json: JSONValue { .object([(key: "time", value: .string(time)), (key: "id", value: .number(Double(id)))]) }
        /// Is self after other?
        func after(_ other: Cursor?) -> Bool {
            guard let other else { return true }
            return time != other.time ? time > other.time : id > other.id
        }
    }

    struct Meta {
        var seq = ""
        var docCount = 0
        var catchup: Cursor?
        var backfill: Cursor?
        var backfillDone = false
        var buildStarted: String?
        var total = 0
    }

    public static let buildPages = 25
    public static let catchupBatch = 5000
    public static let mergeAt = 2000

    @Published public private(set) var status = Status()

    private let api: MailAPI
    private let fortress: FortressSession
    private let userID: Int
    private var index: MailSearchCore.Index?
    private var meta = Meta()
    private var store: FileShardStore?
    private var catching: Task<Void, Error>?
    private var building: Task<Void, Never>?
    private var epoch = 0

    public init(api: MailAPI, fortress: FortressSession, userID: Int) {
        self.api = api
        self.fortress = fortress
        self.userID = userID
        fortress.session.heldKeys.onLock { [weak self] _ in
            Task { @MainActor in self?.stop() }
        }
        fortress.session.addSignOutHook { [weak self] in
            Task { @MainActor in self?.stop() }
        }
    }

    nonisolated static var root: URL {
        FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask)[0]
            .appendingPathComponent("mailsearch", isDirectory: true)
    }

    // MARK: Starting and stopping

    /// Stop whatever runs; the saved index stays sealed on disk.
    public func stop() {
        epoch += 1
        catching?.cancel()
        building?.cancel()
        catching = nil
        building = nil
        index = nil
        store = nil
    }

    private func start() throws {
        if index != nil { return }
        guard let secret = fortress.vault.heldSecret else { throw FortressError.locked }
        let key = HKDF<SHA256>.deriveKey(inputKeyMaterial: SymmetricKey(data: secret), salt: Data(),
                                         info: Data("joinery-mailsearch:record:v1".utf8), outputByteCount: 32)
        let fp = SHA256.hash(data: key.withUnsafeBytes { Data($0) }).prefix(8).map { String(format: "%02x", $0) }.joined()
        let dir = Self.root.appendingPathComponent(String(userID), isDirectory: true)
        let s = try FileShardStore(dir: dir, key: key, userID: userID)
        let head = s.readHead()
        if head?.format != MailSearchCore.format || head?.fp != fp {
            try s.clear()
            try s.writeHead(format: MailSearchCore.format, fp: fp)
            meta = Meta()
            index = MailSearchCore.Index()
        } else {
            meta = s.readMeta() ?? Meta()
            let ids = (try? MailSearchCore.decodeIds(s.read("ids") ?? [0])) ?? []
            let tail = (try? MailSearchCore.shardToMap(s.read("tail"))) ?? [:]
            index = MailSearchCore.Index(ids: ids, tail: tail)
            if index!.ids.count != meta.docCount { meta.docCount = index!.ids.count }
        }
        store = s
        publish()
    }

    private func publish(failed: String? = nil) {
        status = Status(indexed: meta.docCount, total: max(meta.total, meta.docCount),
                        building: !meta.backfillDone, failed: failed)
    }

    // MARK: Reading the mail

    /// Open a page's search texts. A row that will not open is left out; a page
    /// where none opens stops the walk (a rotation changed the vault under it).
    private func openEntries(_ entries: [JSONValue]) throws -> [(Int, String)] {
        guard let secret = fortress.vault.heldSecret else { throw FortressError.locked }
        var tried = 0
        var out: [(Int, String)] = []
        for e in entries {
            guard let id = e["id"]?.intValue, let s = e["sealed"],
                  let text = s["iem_search_text"]?.stringValue, let dek = s["sealed_dek"]?.stringValue else { continue }
            tried += 1
            do {
                let key = try VaultCrypto.openDek(dek, scope: FortressSession.scope, secret: secret)
                let packed = try VaultCrypto.openField(text, key: key,
                                                       ad: "\(s["sealed_ad_prefix"]?.stringValue ?? "mail:")\(id):iem_search_text")
                out.append((id, try Gzip.inflateSearchText(packed)))
            } catch {
                continue
            }
        }
        if tried > 0 && out.isEmpty { throw FortressError.message("Mail could not be opened for search on this phone.") }
        return out
    }

    private func page(_ args: [(key: String, value: JSONValue)]) async throws -> JSONValue {
        try await api.action("mailbox/search_entries", args)
    }

    /// Add entries and move cursors (forward only); save when anything changed.
    private func add(_ entries: [(Int, String)], catchup: Cursor? = nil, backfill: Cursor? = nil,
                     backfillDone: Bool = false, buildStarted: String? = nil, total: Int? = nil) throws {
        guard var idx = index, let store else { throw FortressError.locked }
        var changed = false
        for (id, text) in entries where idx.add(id, text) { changed = true }
        if let c = catchup, c.after(meta.catchup) { meta.catchup = c; changed = true }
        if let b = backfill, meta.backfill == nil || meta.backfill!.after(b) { meta.backfill = b; changed = true }
        if backfillDone && !meta.backfillDone { meta.backfillDone = true; changed = true }
        if let b = buildStarted, meta.buildStarted == nil { meta.buildStarted = b; changed = true }
        if let t = total, t != meta.total { meta.total = t; changed = true }
        guard changed else { index = idx; return }
        if idx.tailDocs >= Self.mergeAt { try idx.merge(store) }
        meta.docCount = idx.ids.count
        meta.seq = VaultCrypto.randomHex(8)
        try store.write("ids", MailSearchCore.encodeIds(idx.ids))
        try store.write("tail", try MailSearchCore.mapToShard(idx.tail))
        try store.writeMeta(meta)
        index = idx
        publish()
    }

    /// New mail since this phone last looked; the first page reaches back ten minutes.
    private func catchUp() async throws {
        if let catching { return try await catching.value }
        let at = epoch
        let task = Task { @MainActor in
            guard let started = meta.buildStarted else { return }
            var cur = meta.catchup ?? Cursor(time: started, id: 0)
            var batch: [(Int, String)] = []
            var last: Cursor?
            var first = true
            while true {
                let p = try await page([(key: "order", value: .string("new")), (key: "time", value: .string(cur.time)),
                                        (key: "id", value: .number(Double(cur.id))), (key: "overlap", value: .bool(first))])
                first = false
                batch += try openEntries(p["entries"]?.arrayValue ?? [])
                if let l = Cursor(p["last"]) { last = l }
                guard at == epoch else { return }
                let next = Cursor(p["next"])
                if next == nil || batch.count >= Self.catchupBatch {
                    if !batch.isEmpty || last != nil { try add(batch, catchup: last) }
                    batch = []
                }
                guard let n = next else { break }
                cur = n
            }
        }
        catching = task
        defer { if catching == task { catching = nil } }
        try await task.value
    }

    /// The first build: newest first, in the background, while the key is held.
    private func backfill() {
        guard building == nil, !meta.backfillDone, index != nil, status.failed == nil else { return }
        let at = epoch
        building = Task { @MainActor in
            defer { if at == epoch { building = nil } }
            do {
                var cur = meta.backfill
                var batch: [(Int, String)] = []
                var pendNext: Cursor?
                var pendStarted: String?
                var pendCatchup: Cursor?
                var total: Int?
                var withTotal = true
                var n = 0
                while at == epoch, fortress.isOpen {
                    n += 1
                    var args: [(key: String, value: JSONValue)] = [(key: "order", value: .string("old")),
                                                                  (key: "with_total", value: .bool(withTotal))]
                    if let cur {
                        args.append((key: "time", value: .string(cur.time)))
                        args.append((key: "id", value: .number(Double(cur.id))))
                    }
                    let p = try await page(args)
                    if withTotal { total = p["total"]?.intValue; withTotal = false }
                    if meta.buildStarted == nil && pendStarted == nil, let st = p["server_time"]?.stringValue {
                        // Mail written from here on is the catch-up's.
                        pendStarted = st
                        pendCatchup = Cursor(time: st, id: 0)
                    }
                    batch += try openEntries(p["entries"]?.arrayValue ?? [])
                    guard at == epoch else { return }
                    let next = Cursor(p["next"])
                    if let next { pendNext = next }
                    // The first page saves at once, so the newest mail is searchable in seconds.
                    if next == nil || n == 1 || n % Self.buildPages == 0 {
                        try add(batch, catchup: pendCatchup, backfill: pendNext, backfillDone: next == nil,
                                buildStarted: pendStarted, total: total)
                        batch = []; pendNext = nil; pendStarted = nil; pendCatchup = nil; total = nil
                    }
                    guard let next else { return }
                    cur = next
                }
            } catch {
                guard at == epoch else { return }
                publish(failed: (error as? LocalizedError)?.errorDescription ?? "the index could not be saved")
            }
        }
    }

    // MARK: What the mailbox calls

    /// Catch up with new mail, then search: `packed` for thread_list's
    /// device_hits, and a line to show while the index is incomplete.
    public func hits(_ q: String) async -> (packed: String?, note: String?) {
        guard fortress.isOpen else { return (nil, "Unlock your mail to search end-to-end encrypted messages.") }
        do {
            try start()
            try await catchUp()
            guard let index, let store else { return (nil, nil) }
            let ids = try index.search(store, q)
            backfill()
            return (MailSearchCore.packIds(ids), status.line)
        } catch {
            let m = (error as? LocalizedError)?.errorDescription ?? "the index could not be read"
            publish(failed: m)
            return (nil, status.line)
        }
    }

    /// Start building in the background (the mailbox opened with the key held).
    public func warm() {
        guard fortress.isOpen else { return }
        do {
            try start()
            backfill()
        } catch {
            publish(failed: (error as? LocalizedError)?.errorDescription ?? "the index could not be read")
        }
    }

    public func rebuild() async {
        stop()
        do {
            try FileManager.default.removeItem(at: Self.root.appendingPathComponent(String(userID)))
        } catch {}
        status = Status()
        warm()
    }

    public func remove() {
        stop()
        try? FileManager.default.removeItem(at: Self.root.appendingPathComponent(String(userID)))
        status = Status()
    }
}

/// The sealed records on disk (the browser's names, AD and gzip-then-seal).
final class FileShardStore: MailSearchCore.ShardStore {
    let dir: URL
    let key: SymmetricKey
    let userID: Int

    init(dir: URL, key: SymmetricKey, userID: Int) throws {
        self.dir = dir
        self.key = key
        self.userID = userID
        try FileManager.default.createDirectory(at: dir, withIntermediateDirectories: true,
                                                attributes: [.protectionKey: FileProtectionType.complete])
        var values = URLResourceValues()
        values.isExcludedFromBackup = true
        var root = DeviceSearch.root
        try? root.setResourceValues(values)
    }

    static func shardName(_ n: Int) -> String { String(format: "shard:%02x", n) }

    func ad(_ record: String) -> String { "mailsearch:\(userID):\(record):\(MailSearchCore.format)" }

    private func url(_ record: String) -> URL {
        dir.appendingPathComponent(record.replacingOccurrences(of: ":", with: "_"))
    }

    func read(_ record: String) -> [UInt8]? {
        guard let raw = try? Data(contentsOf: url(record)), raw.count > 28 else { return nil }
        do {
            let body = try VaultCrypto.gcmOpen(Data(raw.suffix(from: raw.startIndex + 12)), iv: Data(raw.prefix(12)),
                                               key: key, ad: Data(ad(record).utf8))
            return [UInt8](try Gzip.inflate(body))
        } catch {
            return nil
        }
    }

    func write(_ record: String, _ bytes: [UInt8]) throws {
        let gz = try Gzip.deflate(Data(bytes))
        let nonce = AES.GCM.Nonce()
        let box = try AES.GCM.seal(gz, using: key, nonce: nonce, authenticating: Data(ad(record).utf8))
        try (Data(nonce) + box.ciphertext + box.tag).write(to: url(record), options: [.atomic, .completeFileProtection])
    }

    func get(_ shard: Int) throws -> [UInt8]? { read(Self.shardName(shard)) }
    func put(_ shard: Int, _ bytes: [UInt8]) throws { try write(Self.shardName(shard), bytes) }

    func readHead() -> (format: Int, fp: String)? {
        guard let d = try? Data(contentsOf: url("head")), let v = try? JSONValue.parse(d) else { return nil }
        return (v["format"]?.intValue ?? 0, v["fp"]?.stringValue ?? "")
    }

    func writeHead(format: Int, fp: String) throws {
        let v = JSONValue.object([(key: "format", value: .number(Double(format))), (key: "fp", value: .string(fp))])
        try v.encodedData().write(to: url("head"), options: [.atomic, .completeFileProtection])
    }

    func clear() throws {
        for f in (try? FileManager.default.contentsOfDirectory(at: dir, includingPropertiesForKeys: nil)) ?? [] {
            try? FileManager.default.removeItem(at: f)
        }
    }

    func readMeta() -> DeviceSearch.Meta? {
        guard let b = read("meta"), let v = try? JSONValue.parse(Data(b)) else { return nil }
        var m = DeviceSearch.Meta()
        m.seq = v["seq"]?.stringValue ?? ""
        m.docCount = v["doc_count"]?.intValue ?? 0
        m.catchup = DeviceSearch.Cursor(v["catchup"])
        m.backfill = DeviceSearch.Cursor(v["backfill"])
        m.backfillDone = v["backfill_done"]?.boolValue ?? false
        let bs = v["build_started"]
        m.buildStarted = (bs?.isNull ?? true) ? nil : bs?.stringValue
        m.total = v["total"]?.intValue ?? 0
        return m
    }

    func writeMeta(_ m: DeviceSearch.Meta) throws {
        let v = JSONValue.object([
            (key: "seq", value: .string(m.seq)), (key: "doc_count", value: .number(Double(m.docCount))),
            (key: "catchup", value: m.catchup?.json ?? .null), (key: "backfill", value: m.backfill?.json ?? .null),
            (key: "backfill_done", value: .bool(m.backfillDone)),
            (key: "build_started", value: m.buildStarted.map { .string($0) } ?? .null),
            (key: "total", value: .number(Double(m.total))),
        ])
        try write("meta", [UInt8](v.encodedData()))
    }
}
