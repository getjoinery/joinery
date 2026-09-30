import Foundation
import JoineryKit

/// A mailbox's mail rules evaluated on the phone that opens a relay-sealed
/// message (specs/fortress_mobile_apps.md § R14) — a port of
/// `InboundEmailFilter::matches()` by way of the browser's
/// `mailbox_filter_match.js`, with PHP's semantics: its trim() set,
/// preg_split('/\s+/u'), intval(), mb_strtolower(), and byte-exact substring
/// search (no Unicode equivalence). The phone only reports which rules
/// matched; the server applies their actions.
///
/// plugins/mailbox/tests/fixtures/filter_match_cases.json (written from the PHP
/// matcher) is the oracle.
public enum MailRuleMatch {
    /// A rule as `mailbox/device_rules` lists it.
    public struct Rule: Equatable, Sendable {
        public let id: Int
        public let from, to, subject, hasWords, excludes, sizeOp: String
        public let sizeBytes: Int
        public let hasAttachment: Bool
        /// A forward rule: the opened message goes back with the outcome.
        public let forwards: Bool

        public init?(json: JSONValue?) {
            guard let json, let id = json["id"].map({ MailRuleMatch.intval($0) }) else { return nil }
            let m = json["match"] ?? .object([])
            self.id = id
            from = MailRuleMatch.str(m["from"])
            to = MailRuleMatch.str(m["to"])
            subject = MailRuleMatch.str(m["subject"])
            hasWords = MailRuleMatch.str(m["has_words"])
            excludes = MailRuleMatch.str(m["excludes"])
            sizeOp = MailRuleMatch.str(m["size_op"])
            sizeBytes = m["size_bytes"].map { MailRuleMatch.intval($0) } ?? 0
            hasAttachment = m["has_attachment"] == .bool(true)
            forwards = json["forwards"] == .bool(true)
        }
    }

    /// What a rule is matched against.
    public struct Message: Sendable {
        public var sender = "", recipient = "", subject = "", bodyPlain = "", bodyHTML = ""
        public var sizeBytes = 0
        public var hasAttachment = false

        public init(sender: String = "", recipient: String = "", subject: String = "", bodyPlain: String = "",
                    bodyHTML: String = "", sizeBytes: Int = 0, hasAttachment: Bool = false) {
            self.sender = sender; self.recipient = recipient; self.subject = subject
            self.bodyPlain = bodyPlain; self.bodyHTML = bodyHTML
            self.sizeBytes = sizeBytes; self.hasAttachment = hasAttachment
        }
    }

    static let phpTrim: Set<Unicode.Scalar> = [" ", "\t", "\n", "\r", "\0", "\u{0B}"]
    /// PCRE's \s with /u, as PHP matches it.
    static let ucpSpace: Set<Unicode.Scalar> = {
        var s: Set<Unicode.Scalar> = ["\t", "\n", "\u{0B}", "\u{0C}", "\r", " ", "\u{85}", "\u{A0}", "\u{1680}",
                                      "\u{180E}", "\u{2028}", "\u{2029}", "\u{202F}", "\u{205F}", "\u{3000}"]
        for v in 0x2000...0x200A { s.insert(Unicode.Scalar(v)!) }
        return s
    }()

    static func str(_ v: JSONValue?) -> String {
        switch v {
        case .none, .some(.null), .some(.bool(false)): return ""
        case .some(.bool(true)): return "1"
        case .some(let x): return x.stringValue ?? ""
        }
    }

    /// PHP intval() over a JSON value.
    static func intval(_ v: JSONValue) -> Int {
        switch v {
        case .number(let n): return n.isFinite ? Int(n.rounded(.towardZero)) : 0
        case .bool(let b): return b ? 1 : 0
        case .string(let s):
            let pattern = "^[ \\t\\n\\r\\x0B\\f]*[+-]?(\\d+(\\.\\d*)?|\\.\\d+)([eE][+-]?\\d+)?"
            guard let r = s.range(of: pattern, options: .regularExpression),
                  let d = Double(s[r].trimmingCharacters(in: .whitespacesAndNewlines)) else { return 0 }
            return d.isFinite ? Int(d.rounded(.towardZero)) : 0
        default: return 0
        }
    }

    static func trim(_ s: String) -> String {
        var scalars = Array(s.unicodeScalars)
        while let f = scalars.first, phpTrim.contains(f) { scalars.removeFirst() }
        while let l = scalars.last, phpTrim.contains(l) { scalars.removeLast() }
        return String(String.UnicodeScalarView(scalars))
    }

    /// PHP mb_strtolower(): the full lower-case mapping, scalar by scalar, with
    /// Unicode's one context rule — a capital sigma at the end of a word is ς
    /// (Final_Sigma: a cased letter before it, none after, case-ignorables
    /// skipped). Swift's own lowercased() maps scalar by scalar without it.
    static func lower(_ s: String) -> String {
        let u = Array(s.unicodeScalars)
        func cased(_ x: Unicode.Scalar) -> Bool { x.properties.isCased }
        func ignorable(_ x: Unicode.Scalar) -> Bool { x.properties.isCaseIgnorable }
        var out = ""
        for (i, sc) in u.enumerated() {
            if sc == "\u{03A3}" {
                var j = i - 1
                while j >= 0 && ignorable(u[j]) { j -= 1 }
                let before = j >= 0 && cased(u[j])
                var k = i + 1
                while k < u.count && ignorable(u[k]) { k += 1 }
                let after = k < u.count && cased(u[k])
                out += (before && !after) ? "\u{03C2}" : "\u{03C3}"
                continue
            }
            out += sc.properties.lowercaseMapping
        }
        return out
    }

    /// Byte-exact substring (PHP mb_strpos over lower-cased text).
    static func has(_ hay: String, _ needle: String) -> Bool {
        hay.range(of: needle, options: .literal) != nil
    }

    static func anyTermIn(_ list: String, _ hay: String) -> Bool {
        for term in list.components(separatedBy: ",") {
            let t = lower(trim(term))
            if !t.isEmpty && has(hay, t) { return true }
        }
        return false
    }

    static func tokens(_ phrase: String) -> [String] {
        var out: [String] = []
        var cur = String.UnicodeScalarView()
        for s in trim(phrase).unicodeScalars {
            if ucpSpace.contains(s) {
                let t = lower(trim(String(cur)))
                if !t.isEmpty { out.append(t) }
                cur = String.UnicodeScalarView()
            } else {
                cur.append(s)
            }
        }
        let t = lower(trim(String(cur)))
        if !t.isEmpty { out.append(t) }
        return out
    }

    public static func matches(_ c: Rule, _ m: Message) -> Bool {
        let sender = lower(m.sender)
        let recipient = lower(m.recipient)
        let subject = lower(m.subject)
        let hay = lower(m.sender + " " + m.subject + " " + m.bodyPlain + " " + m.bodyHTML)

        let from = trim(c.from)
        if !from.isEmpty && !anyTermIn(from, sender) { return false }
        let to = trim(c.to)
        if !to.isEmpty && !anyTermIn(to, recipient) { return false }
        let subj = trim(c.subject)
        if !subj.isEmpty && !has(subject, lower(subj)) { return false }
        let words = trim(c.hasWords)
        if !words.isEmpty {
            for w in tokens(words) where !has(hay, w) { return false }
        }
        let excl = trim(c.excludes)
        if !excl.isEmpty {
            for x in tokens(excl) where has(hay, x) { return false }
        }
        if (c.sizeOp == "gt" || c.sizeOp == "lt") && c.sizeBytes > 0 {
            if c.sizeOp == "gt" && !(m.sizeBytes > c.sizeBytes) { return false }
            if c.sizeOp == "lt" && !(m.sizeBytes < c.sizeBytes) { return false }
        }
        if c.hasAttachment && !m.hasAttachment { return false }
        return true
    }

    /// The ids of the rules that match, in the order given.
    public static func matchingIDs(_ rules: [Rule], _ m: Message) -> [Int] {
        rules.filter { matches($0, m) }.map(\.id)
    }
}
