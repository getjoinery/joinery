import Foundation

/// The pure half of this phone's search index over end-to-end (Fortress) mail —
/// a port of the browser's `mailbox_search_core.js` (specs/fortress_mobile_apps.md
/// § R5, D2). No keys, no storage, no network. The node gate's vectors
/// (plugins/mailbox/tests/fixtures/search_core_vectors.json) are the oracle:
/// same words, same bytes, same answers as the browser.
///
/// A word maps to the messages that contain it, nothing else. Messages are
/// numbered 0, 1, 2… in the order indexed ("docs"); `ids` maps a doc back to its
/// message id. A word's docs are stored as LEB128 gaps, or as a bitmap when that
/// is smaller. Words live in 256 shards by FNV-1a of their UTF-8 bytes; new
/// messages go to a tail until it is merged into the shards.
///
/// Shard bytes: varint word count, then per word in UTF-8 byte order: varint
/// bytes shared with the word before, varint length of the rest, the rest,
/// varint (posting length * 2 + kind: 0 list, 1 bitmap), the posting.
///
/// The tokenizer mirrors SQLite FTS5 unicode61 with remove_diacritics: NFKD,
/// combining marks dropped, lower case, split on anything that is not a letter
/// or a number. Lengths count UTF-16 units, as JavaScript does.
public enum MailSearchCore {
    public static let format = 1
    public static let maxWord = 40
    public static let shards = 256
    static let list: UInt8 = 0, bitmap: UInt8 = 1

    // MARK: Words

    static func isMark(_ s: Unicode.Scalar) -> Bool {
        switch s.properties.generalCategory {
        case .nonspacingMark, .spacingMark, .enclosingMark: return true
        default: return false
        }
    }

    static func isWordScalar(_ s: Unicode.Scalar) -> Bool {
        switch s.properties.generalCategory {
        case .uppercaseLetter, .lowercaseLetter, .titlecaseLetter, .modifierLetter, .otherLetter,
             .decimalNumber, .letterNumber, .otherNumber:
            return true
        default:
            return false
        }
    }

    static func tokens(_ text: String) -> [String] {
        var noMarks = String.UnicodeScalarView()
        for s in text.decomposedStringWithCompatibilityMapping.unicodeScalars where !isMark(s) {
            noMarks.append(s)
        }
        let lower = String(noMarks).lowercased()
        var out: [String] = []
        var cur = String.UnicodeScalarView()
        for s in lower.unicodeScalars {
            if isWordScalar(s) {
                cur.append(s)
            } else {
                out.append(String(cur))
                cur = String.UnicodeScalarView()
            }
        }
        out.append(String(cur))
        return out
    }

    /// The distinct words of a message worth indexing, in first-seen order.
    public static func words(_ text: String) -> [String] {
        var seen = Set<String>()
        var out: [String] = []
        for w in tokens(text) where !w.isEmpty && w.utf16.count <= maxWord && !seen.contains(w) {
            seen.insert(w)
            out.append(w)
        }
        return out
    }

    /// A query's distinct words, long ones kept (they match nothing).
    public static func queryWords(_ q: String) -> [String] {
        var seen = Set<String>()
        var out: [String] = []
        for w in tokens(q) where !w.isEmpty && !seen.contains(w) {
            seen.insert(w)
            out.append(w)
        }
        return out
    }

    public static func fnv1a(_ bytes: [UInt8]) -> UInt32 {
        var h: UInt32 = 0x811c9dc5
        for b in bytes {
            h ^= UInt32(b)
            h = h &* 0x01000193
        }
        return h
    }

    public static func shardOf(_ wordBytes: [UInt8]) -> Int { Int(fnv1a(wordBytes) % UInt32(shards)) }

    static func compareBytes(_ a: ArraySlice<UInt8>, _ b: ArraySlice<UInt8>) -> Int {
        var i = a.startIndex, j = b.startIndex
        while i < a.endIndex && j < b.endIndex {
            if a[i] != b[j] { return Int(a[i]) - Int(b[j]) }
            i += 1; j += 1
        }
        return a.count - b.count
    }

    // MARK: Bytes

    struct Out {
        var buf: [UInt8] = []
        mutating func varint(_ v: Int) {
            var v = UInt64(v)
            while v >= 128 { buf.append(UInt8(v % 128) + 128); v /= 128 }
            buf.append(UInt8(v))
        }
        mutating func bytes<S: Sequence>(_ b: S) where S.Element == UInt8 { buf.append(contentsOf: b) }
    }

    enum Failure: Error { case cutShort }

    struct In {
        let buf: [UInt8]
        var p: Int
        init(_ buf: [UInt8], at p: Int = 0) { self.buf = buf; self.p = p }
        var atEnd: Bool { p >= buf.count }
        mutating func varint() throws -> Int {
            var v: UInt64 = 0, mul: UInt64 = 1
            var b: UInt8
            repeat {
                guard p < buf.count else { throw Failure.cutShort }
                b = buf[p]; p += 1
                v += UInt64(b & 127) * mul
                mul = mul &* 128
            } while b & 128 != 0
            return Int(v)
        }
        mutating func take(_ n: Int) throws -> ArraySlice<UInt8> {
            guard p + n <= buf.count else { throw Failure.cutShort }
            let s = buf[p..<(p + n)]
            p += n
            return s
        }
    }

    // MARK: One word's docs

    public struct Posting: Equatable {
        public let kind: UInt8
        public let bytes: [UInt8]
    }

    static func bitmapLength(_ last: Int) -> Int { (last >> 3) + 1 }

    static func encodeList(_ docs: [Int], prev: Int, _ out: inout Out) {
        var prev = prev
        for d in docs {
            out.varint(prev < 0 ? d : d - prev)
            prev = d
        }
    }

    static func listBytes(_ docs: [Int]) -> [UInt8] {
        var out = Out()
        encodeList(docs, prev: -1, &out)
        return out.buf
    }

    static func bitmapBytes(_ docs: [Int], last: Int) -> [UInt8] {
        var b = [UInt8](repeating: 0, count: bitmapLength(last))
        for d in docs { b[d >> 3] |= UInt8(1 << (d & 7)) }
        return b
    }

    /// The smaller encoding of an ascending doc list; a tie goes to the list.
    public static func posting(_ docs: [Int]) -> Posting {
        let last = docs[docs.count - 1]
        let l = listBytes(docs)
        if bitmapLength(last) < l.count { return Posting(kind: bitmap, bytes: bitmapBytes(docs, last: last)) }
        return Posting(kind: list, bytes: l)
    }

    public static func docsOf(_ p: Posting) -> [Int] {
        var out: [Int] = []
        if p.kind == list {
            var r = In(p.bytes)
            var d = -1
            while !r.atEnd {
                guard let v = try? r.varint() else { break }
                d = d < 0 ? v : d + v
                out.append(d)
            }
            return out
        }
        for (byte, v) in p.bytes.enumerated() where v != 0 {
            for bit in 0..<8 where v & UInt8(1 << bit) != 0 { out.append((byte << 3) + bit) }
        }
        return out
    }

    public static func countOf(_ p: Posting) -> Int {
        if p.kind == list { return p.bytes.filter { $0 < 128 }.count }
        return p.bytes.reduce(0) { $0 + $1.nonzeroBitCount }
    }

    public static func lastOf(_ p: Posting) -> Int {
        if p.kind == bitmap {
            for byte in stride(from: p.bytes.count - 1, through: 0, by: -1) where p.bytes[byte] != 0 {
                return (byte << 3) + (7 - p.bytes[byte].leadingZeroBitCount)
            }
            return -1
        }
        return docsOf(p).last ?? -1
    }

    /// `p` with more docs appended (every one past p's last, ascending) — the
    /// same choice `posting()` makes, so a merged shard is byte-for-byte the
    /// shard a build from scratch writes.
    public static func extend(_ p: Posting?, _ more: [Int]) -> Posting {
        guard let p else { return posting(more) }
        let last = more[more.count - 1]
        if p.kind == list {
            var out = Out()
            out.bytes(p.bytes)
            encodeList(more, prev: lastOf(p), &out)
            if bitmapLength(last) < out.buf.count {
                return Posting(kind: bitmap, bytes: bitmapBytes(docsOf(p) + more, last: last))
            }
            return Posting(kind: list, bytes: out.buf)
        }
        var b = [UInt8](repeating: 0, count: bitmapLength(last))
        for (i, v) in p.bytes.enumerated() { b[i] = v }
        for d in more { b[d >> 3] |= UInt8(1 << (d & 7)) }
        // Gone sparse (a word common early and rare since): a list may be smaller now.
        if countOf(p) + more.count <= b.count {
            let asList = listBytes(docsOf(p) + more)
            if asList.count <= b.count { return Posting(kind: list, bytes: asList) }
        }
        return Posting(kind: bitmap, bytes: b)
    }

    // MARK: Shards

    struct Writer {
        var out = Out()
        var prev: [UInt8] = []
        var words = 0
        mutating func entry(_ word: ArraySlice<UInt8>, _ p: Posting) {
            let n = min(prev.count, word.count)
            var k = 0
            let w = Array(word)
            while k < n && prev[k] == w[k] { k += 1 }
            out.varint(k)
            out.varint(w.count - k)
            out.bytes(w[k...])
            out.varint(p.bytes.count * 2 + Int(p.kind))
            out.bytes(p.bytes)
            prev = w
            words += 1
        }
        func done() -> [UInt8] {
            var head = Out()
            head.varint(words)
            return head.buf + out.buf
        }
    }

    struct Reader {
        var r: In
        var left: Int
        var word: [UInt8] = []
        init(_ shard: [UInt8]) throws {
            r = In(shard)
            left = try r.varint()
        }
        mutating func next() throws -> (word: [UInt8], p: Posting)? {
            guard left > 0 else { return nil }
            left -= 1
            let k = try r.varint()
            let rest = try r.take(try r.varint())
            word = Array(word.prefix(k)) + rest
            let lk = try r.varint()
            return (word, Posting(kind: UInt8(lk % 2), bytes: Array(try r.take(lk / 2))))
        }
    }

    public struct Add {
        public let word: [UInt8]
        public let docs: [Int]
    }

    /// A shard with `adds` merged in (sorted by word bytes, every doc past
    /// anything the shard holds for it). One pass over both.
    public static func mergeShard(_ shard: [UInt8]?, _ adds: [Add]) throws -> [UInt8] {
        var w = Writer()
        var rd = try shard.map { try Reader($0) }
        var cur = try rd?.next()
        var i = 0
        while cur != nil || i < adds.count {
            let c: Int = cur == nil ? 1 : (i >= adds.count ? -1 : compareBytes(cur!.word[...], adds[i].word[...]))
            if c < 0 {
                w.entry(cur!.word[...], cur!.p)
            } else if c > 0 {
                w.entry(adds[i].word[...], posting(adds[i].docs))
                i += 1
            } else {
                w.entry(cur!.word[...], extend(cur!.p, adds[i].docs))
                i += 1
            }
            if c <= 0 { cur = try rd?.next() }
        }
        return w.done()
    }

    /// One word's posting in a shard, or nil. Words are sorted, so it stops early.
    public static func findWord(_ shard: [UInt8]?, _ word: [UInt8]) throws -> Posting? {
        guard let shard else { return nil }
        var rd = try Reader(shard)
        while let e = try rd.next() {
            let c = compareBytes(e.word[...], word[...])
            if c == 0 { return e.p }
            if c > 0 { return nil }
        }
        return nil
    }

    /// Every word of a shard (the tail), word → docs.
    public static func shardToMap(_ shard: [UInt8]?) throws -> [String: [Int]] {
        var m: [String: [Int]] = [:]
        guard let shard else { return m }
        var rd = try Reader(shard)
        while let e = try rd.next() { m[String(decoding: e.word, as: UTF8.self)] = docsOf(e.p) }
        return m
    }

    static func sortedAdds(_ map: [String: [Int]]) -> [Add] {
        map.map { Add(word: Array($0.key.utf8), docs: $0.value) }
            .sorted { compareBytes($0.word[...], $1.word[...]) < 0 }
    }

    public static func mapToShard(_ map: [String: [Int]]) throws -> [UInt8] {
        try mergeShard(nil, sortedAdds(map))
    }

    // MARK: ids

    /// Each id as its signed difference from the one before, zigzagged.
    public static func encodeIds(_ ids: [Int]) -> [UInt8] {
        var out = Out()
        out.varint(ids.count)
        var prev = 0
        for id in ids {
            let d = id - prev
            out.varint(d >= 0 ? d * 2 : -d * 2 - 1)
            prev = id
        }
        return out.buf
    }

    public static func decodeIds(_ bytes: [UInt8]) throws -> [Int] {
        var r = In(bytes)
        let n = try r.varint()
        var ids: [Int] = []
        ids.reserveCapacity(n)
        var prev = 0
        for _ in 0..<n {
            let z = try r.varint()
            prev += z % 2 == 0 ? z / 2 : -(z + 1) / 2
            ids.append(prev)
        }
        return ids
    }

    /// Ascending message ids as base64 LEB128 gaps: what thread_list reads as device_hits.
    public static func packIds(_ sorted: [Int]) -> String {
        var out = Out()
        var prev = 0
        for id in sorted {
            out.varint(id - prev)
            prev = id
        }
        return Data(out.buf).base64EncodedString()
    }

    // MARK: The index

    /// A shard store: get/put by shard number.
    public protocol ShardStore {
        func get(_ shard: Int) throws -> [UInt8]?
        func put(_ shard: Int, _ bytes: [UInt8]) throws
    }

    /// The in-memory half of an index: ids and the tail.
    public struct Index {
        public private(set) var ids: [Int]
        var known: Set<Int>
        public private(set) var tail: [String: [Int]]
        public private(set) var tailDocs: Int

        public init(ids: [Int] = [], tail: [String: [Int]] = [:]) {
            self.ids = ids
            self.known = Set(ids)
            self.tail = tail
            var from = ids.count
            for docs in tail.values { if let f = docs.first, f < from { from = f } }
            self.tailDocs = ids.count - from
        }

        public func has(_ id: Int) -> Bool { known.contains(id) }

        /// Add one message; false when it is already indexed.
        @discardableResult
        public mutating func add(_ id: Int, _ text: String) -> Bool {
            if known.contains(id) { return false }
            let doc = ids.count
            ids.append(id)
            known.insert(id)
            for w in MailSearchCore.words(text) { tail[w, default: []].append(doc) }
            tailDocs += 1
            return true
        }

        /// Merge the tail into the shards it touches; the shard numbers written.
        @discardableResult
        public mutating func merge(_ store: ShardStore) throws -> [Int] {
            var groups: [Int: [Add]] = [:]
            for a in sortedAdds(tail) { groups[shardOf(a.word), default: []].append(a) }
            let keys = groups.keys.sorted()
            for k in keys { try store.put(k, try mergeShard(try store.get(k), groups[k]!)) }
            tail = [:]
            tailDocs = 0
            return keys
        }

        /// The message ids holding every word of `q`, ascending.
        public func search(_ store: ShardStore, _ q: String) throws -> [Int] {
            let qw = queryWords(q)
            if qw.isEmpty { return [] }
            var postings: [Posting?] = []
            var tails: [[Int]?] = []
            for w in qw {
                if w.utf16.count > maxWord { return [] }
                let wb = Array(w.utf8)
                postings.append(try findWord(try store.get(shardOf(wb)), wb))
                tails.append(tail[w])
            }
            let docs = intersectPostings(postings) + intersectLists(tails)
            return docs.map { ids[$0] }.sorted()
        }
    }

    static func intersectPostings(_ ps: [Posting?]) -> [Int] {
        var lists: [Posting] = [], maps: [Posting] = []
        for p in ps {
            guard let p else { return [] }
            if p.kind == bitmap { maps.append(p) } else { lists.append(p) }
        }
        var and: [UInt8]? = nil
        if !maps.isEmpty {
            let len = maps.map(\.bytes.count).min()!
            var a = Array(maps[0].bytes.prefix(len))
            for m in maps.dropFirst() { for k in 0..<len { a[k] &= m.bytes[k] } }
            and = a
        }
        if lists.isEmpty { return docsOf(Posting(kind: bitmap, bytes: and ?? [])) }
        lists.sort { $0.bytes.count < $1.bytes.count }
        var docs = docsOf(lists[0])
        if let and {
            docs = docs.filter { ($0 >> 3) < and.count && (and[$0 >> 3] & UInt8(1 << ($0 & 7))) != 0 }
        }
        for other in lists.dropFirst() where !docs.isEmpty {
            let set = Set(docsOf(other))
            docs = docs.filter { set.contains($0) }
        }
        return docs
    }

    static func intersectLists(_ ls: [[Int]?]) -> [Int] {
        var all: [[Int]] = []
        for l in ls { guard let l else { return [] }; all.append(l) }
        all.sort { $0.count < $1.count }
        var docs = all[0]
        for other in all.dropFirst() where !docs.isEmpty {
            let set = Set(other)
            docs = docs.filter { set.contains($0) }
        }
        return docs
    }
}
