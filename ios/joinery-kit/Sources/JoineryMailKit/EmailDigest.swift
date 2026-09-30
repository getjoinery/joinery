import Foundation
import JoineryKit

/// The AI email digest, built on the phone — a port of the browser's
/// `email-digest.js`, itself a byte-for-byte port of
/// EmailSecurityDigest::buildFromColumns(), EmailAttachmentDigest::buildFromManifest()
/// and UntrustedEnvelope's wrap (specs/fortress_mobile_apps.md § R13).
///
/// The model must see exactly what a server run would show it, so every PHP
/// step keeps PHP's semantics: strip_tags()'s state machine,
/// html_entity_decode() with the HTML5 table (Resources/html_entities.json, the
/// browser's html-entities.js), PHP's trim() set, PCRE's \s with and without
/// /u, lengths in code points, parse_url()'s host. The vectors in
/// plugins/mailbox/tests/fixtures/device_ai_vectors.json are the oracle.
public enum EmailDigest {
    static let subjectCap = 1024
    static let bodyCap = 4096
    static let urlCap = 20
    static let domainCap = 15
    static let anchorTextCap = 120
    static let whitespaceAnnotateThreshold = 200
    static let attMaxParts = 10
    static let attFilenameCap = 120

    typealias Scalars = [Unicode.Scalar]

    /// [ \t\u00A0\u200B\u200C\u200D\u2060\uFEFF\u3000]{4,}
    static let whitespaceRun: Set<Unicode.Scalar> = [" ", "\t", "\u{A0}", "\u{200B}", "\u{200C}", "\u{200D}",
                                                      "\u{2060}", "\u{FEFF}", "\u{3000}"]
    static let sASCII = "[ \\t\\n\\x0B\\f\\r]"
    /// PCRE's \s with /u as PHP matches it.
    static let sUCP = "[\\t\\n\\x0B\\f\\r \\x{0085}\\x{00A0}\\x{1680}\\x{180E}\\x{2000}-\\x{200A}\\x{2028}\\x{2029}\\x{202F}\\x{205F}\\x{3000}]"
    static let phpTrim: Set<Unicode.Scalar> = [" ", "\t", "\n", "\r", "\0", "\u{0B}"]

    static let entities: [String: String] = {
        guard let url = Bundle.module.url(forResource: "html_entities", withExtension: "json"),
              let data = try? Data(contentsOf: url),
              let obj = try? JSONSerialization.jsonObject(with: data) as? [String: String] else { return [:] }
        return obj
    }()

    // MARK: PHP's primitives

    static func sc(_ s: String) -> Scalars { Array(s.unicodeScalars) }
    static func str(_ s: Scalars) -> String { String(String.UnicodeScalarView(s)) }
    static func str<S: Sequence>(_ s: S) -> String where S.Element == Unicode.Scalar {
        var v = String.UnicodeScalarView(); v.append(contentsOf: s); return String(v)
    }
    static func mbLen(_ s: String) -> Int { s.unicodeScalars.count }
    static func mbSub(_ s: String, _ n: Int) -> String { str(s.unicodeScalars.prefix(n)) }

    static func trim(_ s: String, _ chars: Set<Unicode.Scalar> = phpTrim) -> String {
        var u = sc(s)
        var a = 0, b = u.count
        while a < b && chars.contains(u[a]) { a += 1 }
        while b > a && chars.contains(u[b - 1]) { b -= 1 }
        u = Array(u[a..<b])
        return str(u)
    }

    static func rtrim(_ s: String, _ chars: Set<Unicode.Scalar>) -> String {
        var u = sc(s)
        while let l = u.last, chars.contains(l) { u.removeLast() }
        return str(u)
    }

    static func asciiLower(_ s: String) -> String {
        str(s.unicodeScalars.map { ($0.value >= 65 && $0.value <= 90) ? Unicode.Scalar($0.value + 32)! : $0 })
    }

    static func isCSpace(_ c: Unicode.Scalar) -> Bool { [" ", "\t", "\n", "\u{0B}", "\u{0C}", "\r"].contains(c) }

    /// PHP's strip_tags() with no allowed tags (php_strip_tags_ex's states).
    static func stripTags(_ input: String) -> String {
        let s = sc(input)
        var out = String.UnicodeScalarView()
        var state = 0, depth = 0, br = 0
        var inQ: Unicode.Scalar? = nil
        var lc: Unicode.Scalar? = nil
        var isXml = false
        let n = s.count
        func at(_ i: Int) -> Unicode.Scalar? { i >= 0 && i < n ? s[i] : nil }
        var i = 0
        while i < n {
            defer { i += 1 }
            let c = s[i]
            let prev = at(i - 1)
            if c == "\0" { continue }
            switch state {
            case 0:
                if c == "<" {
                    if inQ != nil { continue }
                    if let nx = at(i + 1), isCSpace(nx) { out.append(c); continue }
                    lc = "<"; state = 1; continue
                }
                if c == ">" {
                    if depth > 0 { depth -= 1; continue }
                    if inQ != nil { continue }
                    out.append(c); continue
                }
                out.append(c)
            case 1:
                if c == "<" {
                    if inQ != nil { continue }
                    if let nx = at(i + 1), isCSpace(nx) { continue }
                    depth += 1; continue
                }
                if c == ">" {
                    if depth > 0 { depth -= 1; continue }
                    if inQ != nil { continue }
                    lc = ">"
                    if isXml && prev == "-" { continue }
                    inQ = nil; state = 0; isXml = false; continue
                }
                if c == "\"" || c == "'" {
                    if i != 0 && (inQ == nil || c == inQ) { inQ = inQ == nil ? c : nil }
                    continue
                }
                if c == "!" && prev == "<" { state = 3; lc = c; continue }
                if c == "?" && prev == "<" { br = 0; state = 2; continue }
            case 2:
                if c == "(" { if lc != "\"" && lc != "'" { lc = "("; br += 1 }; continue }
                if c == ")" { if lc != "\"" && lc != "'" { lc = ")"; br -= 1 }; continue }
                if c == ">" {
                    if depth > 0 { depth -= 1; continue }
                    if inQ != nil { continue }
                    if br == 0 && lc != "\"" && prev == "?" { inQ = nil; state = 0 }
                    continue
                }
                if c == "\"" || c == "'" {
                    if prev != "\\" {
                        if lc == c { lc = nil } else if lc != "\\" { lc = c }
                    }
                    if i != 0 && (inQ == nil || c == inQ) { inQ = inQ == nil ? c : nil }
                    continue
                }
                if (c == "l" || c == "L") && i > 4 && (prev == "m" || prev == "M")
                    && (at(i - 2) == "x" || at(i - 2) == "X") && at(i - 3) == "?" && at(i - 4) == "<" {
                    state = 1; isXml = true
                }
            case 3:
                if c == ">" {
                    if depth > 0 { depth -= 1; continue }
                    if inQ != nil { continue }
                    inQ = nil; state = 0; continue
                }
                if c == "\"" || c == "'" {
                    if i != 0 && prev != "\\" && (inQ == nil || c == inQ) { inQ = inQ == nil ? c : nil }
                    continue
                }
                if c == "-" && i >= 2 && prev == "-" && at(i - 2) == "!" { state = 4; continue }
                if (c == "E" || c == "e") && i > 6 && asciiLower(str(s[(i - 6)..<i])) == "doctyp" { state = 1; continue }
            default:
                // inside <!-- ... -->
                if c == ">" && inQ == nil && i >= 2 && prev == "-" && at(i - 2) == "-" { inQ = nil; state = 0 }
            }
        }
        return String(out)
    }

    static func cpAllowedHTML5(_ cp: UInt32) -> Bool {
        (cp >= 0x20 && cp <= 0x7E) || (cp >= 0x09 && cp <= 0x0D && cp != 0x0B)
            || (cp >= 0xA0 && cp <= 0xD7FF)
            || (cp >= 0xE000 && cp <= 0x10FFFF && (cp & 0xFFFF) < 0xFFFE && (cp < 0xFDD0 || cp > 0xFDEF))
    }

    static func isAlnum(_ c: Unicode.Scalar) -> Bool {
        (c.value >= 48 && c.value <= 57) || (c.value >= 65 && c.value <= 90) || (c.value >= 97 && c.value <= 122)
    }

    static func isHex(_ c: Unicode.Scalar) -> Bool {
        (c.value >= 48 && c.value <= 57) || (c.value >= 65 && c.value <= 70) || (c.value >= 97 && c.value <= 102)
    }

    /// PHP's html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8').
    static func decodeEntities(_ input: String) -> String {
        guard input.contains("&") else { return input }
        let s = sc(input)
        let n = s.count
        var out = String.UnicodeScalarView()
        var i = 0
        while i < n {
            let c = s[i]
            if c != "&" || i + 3 >= n { out.append(c); i += 1; continue }
            if s[i + 1] == "#" {
                var j = i + 2
                let hex = j < n && (s[j] == "x" || s[j] == "X")
                if hex { j += 1 }
                let start = j
                while j < n && (hex ? isHex(s[j]) : (s[j].value >= 48 && s[j].value <= 57)) { j += 1 }
                let next = j
                if j == start || j >= n || s[j] != ";" { out.append(contentsOf: s[i..<next]); i = next; continue }
                var digits = str(s[start..<j])
                while digits.count > 1 && digits.hasPrefix("0") { digits.removeFirst() }
                let cp: UInt64? = digits.count > 8 ? nil : UInt64(digits, radix: hex ? 16 : 10)
                guard let v = cp, v <= 0x10FFFF, cpAllowedHTML5(UInt32(v)), v != 0x0D,
                      let scalar = Unicode.Scalar(UInt32(v)) else {
                    out.append(contentsOf: s[i..<next]); i = next; continue
                }
                out.append(scalar)
                i = j + 1
                continue
            }
            var k = i + 1
            while k < n && isAlnum(s[k]) { k += 1 }
            let next = k
            if k == i + 1 || k >= n || s[k] != ";" { out.append(contentsOf: s[i..<next]); i = next; continue }
            guard let rep = entities[str(s[(i + 1)..<k])] else { out.append(contentsOf: s[i..<next]); i = next; continue }
            out.append(contentsOf: rep.unicodeScalars)
            i = k + 1
        }
        return String(out)
    }

    static func re(_ p: String, _ opts: NSRegularExpression.Options = []) -> NSRegularExpression {
        try! NSRegularExpression(pattern: p, options: opts)
    }

    static func replace(_ s: String, _ r: NSRegularExpression, with t: String) -> String {
        r.stringByReplacingMatches(in: s, range: NSRange(location: 0, length: (s as NSString).length),
                                   withTemplate: NSRegularExpression.escapedTemplate(for: t))
    }

    static let schemeRe = re("^([a-zA-Z][a-zA-Z0-9+.\\-]*):")
    static let portRe = re("^[0-9]{1,5}$")

    /// parse_url($url, PHP_URL_HOST), lower-cased as strtolower() does; '' for none.
    static func urlHost(_ url: String) -> String {
        let ns = url as NSString
        var rest: String
        if let m = schemeRe.firstMatch(in: url, range: NSRange(location: 0, length: ns.length)) {
            rest = ns.substring(from: m.range.length)
            guard rest.hasPrefix("//") else { return "" }
            rest = String(rest.dropFirst(2))
        } else if url.hasPrefix("//") {
            rest = String(url.dropFirst(2))
        } else {
            return ""
        }
        let rs = sc(rest)
        let end = rs.firstIndex { $0 == "/" || $0 == "?" || $0 == "#" }
        var auth = str(end.map { Array(rs[..<$0]) } ?? rs)
        if let at = auth.lastIndex(of: "@") { auth = String(auth[auth.index(after: at)...]) }
        var host = auth
        if !(auth.hasPrefix("[") && auth.hasSuffix("]")), let colon = auth.lastIndex(of: ":") {
            let port = String(auth[auth.index(after: colon)...])
            if !port.isEmpty {
                guard portRe.firstMatch(in: port, range: NSRange(location: 0, length: (port as NSString).length)) != nil,
                      let pn = Int(port), pn <= 65535 else { return "" }
            }
            host = String(auth[..<colon])
        }
        if host.isEmpty { return "" }
        return asciiLower(str(host.unicodeScalars.map { ($0.value < 0x20 || $0.value == 0x7F) ? "_" : $0 }))
    }

    // MARK: The digest's own steps

    static func collapse(_ text: String) -> (String, Int) {
        let u = sc(text)
        var out: Scalars = []
        var i = 0
        while i < u.count {
            if whitespaceRun.contains(u[i]) {
                var j = i
                while j < u.count && whitespaceRun.contains(u[j]) { j += 1 }
                if j - i >= 4 { out.append(" ") } else { out.append(contentsOf: u[i..<j]) }
                i = j
            } else {
                out.append(u[i]); i += 1
            }
        }
        return (str(out), max(0, u.count - out.count))
    }

    static func capSize(_ text: String, _ cap: Int) -> String {
        let total = mbLen(text)
        if total <= cap { return text }
        return mbSub(text, cap) + "\n[truncated, \(total) characters total]"
    }

    static func annotation(_ removed: Int) -> String {
        removed <= whitespaceAnnotateThreshold ? "" : "; preprocessor removed \(removed) invisible/whitespace characters"
    }

    static func selectBody(_ plain: String, _ html: String) -> (String, String) {
        if !trim(plain).isEmpty { return (plain, "text/plain") }
        if !trim(html).isEmpty { return (decodeEntities(stripTags(html)), "text/html tag-stripped") }
        return ("(no body text)", "text/plain")
    }

    static let ucpRun = re(sUCP + "+")

    static func anchorText(_ inner: String) -> String {
        var text = trim(replace(decodeEntities(stripTags(inner)), ucpRun, with: " "))
        if mbLen(text) > anchorTextCap { text = mbSub(text, anchorTextCap) + "…" }
        return text
    }

    struct FoundURL { let url: String; var text: String }

    static func addUrl(_ found: inout [FoundURL], _ url: String, _ text: String) {
        let u = trim(url)
        if u.isEmpty { return }
        if let i = found.firstIndex(where: { $0.url == u }) {
            if found[i].text.isEmpty && !text.isEmpty { found[i].text = text }
        } else {
            found.append(FoundURL(url: u, text: text))
        }
    }

    static let anchorRe = re("<a\\b[^>]*href" + sASCII + "*=" + sASCII + "*[\"']([^\"']+)[\"'][^>]*>([\\s\\S]*?)</a>",
                             [.caseInsensitive])
    static let hrefRe = re("href" + sASCII + "*=" + sASCII + "*[\"']([^\"']+)[\"']", [.caseInsensitive])
    static let urlRe = re("\\bhttps?://(?:(?!" + sASCII + ")[^\"'<>])+", [.caseInsensitive])
    static let urlTail: Set<Unicode.Scalar> = [".", ",", ";", ":", "!", "?", ")", "]", "}", "'", "\""]

    static func extractUrls(_ html: String, _ plain: String) -> [FoundURL] {
        var found: [FoundURL] = []
        if !trim(html).isEmpty {
            let ns = html as NSString
            let all = NSRange(location: 0, length: ns.length)
            for m in anchorRe.matches(in: html, range: all) {
                addUrl(&found, decodeEntities(ns.substring(with: m.range(at: 1))), anchorText(ns.substring(with: m.range(at: 2))))
            }
            for m in hrefRe.matches(in: html, range: all) {
                addUrl(&found, decodeEntities(ns.substring(with: m.range(at: 1))), "")
            }
        }
        let visible = trim(plain + " " + stripTags(html))
        let vs = visible as NSString
        for m in urlRe.matches(in: visible, range: NSRange(location: 0, length: vs.length)) {
            addUrl(&found, rtrim(vs.substring(with: m.range), urlTail), "")
        }
        return found
    }

    static func domainSummary(_ urls: [FoundURL]) -> String {
        var order: [String] = []
        var counts: [String: Int] = [:]
        for e in urls {
            let host = urlHost(e.url)
            if host.isEmpty { continue }
            if counts[host] == nil { order.append(host) }
            counts[host, default: 0] += 1
        }
        if order.isEmpty { return "" }
        // Stable, descending by count (PHP 8's arsort).
        let list = order.enumerated().sorted { a, b in
            counts[a.element]! != counts[b.element]! ? counts[a.element]! > counts[b.element]! : a.offset < b.offset
        }.map(\.element)
        var parts = list.prefix(domainCap).map { "\($0) (\(counts[$0]!))" }
        if list.count > domainCap { parts.append("+\(list.count - domainCap) more domains") }
        return parts.joined(separator: ", ")
    }

    static let latin1Labels: Set<String> = ["iso-8859-1", "latin1", "l1", "iso8859-1", "iso_8859-1", "cp819", "ibm819",
                                            "iso-ir-100", "csisolatin1"]
    static let asciiLabels: Set<String> = ["us-ascii", "ascii", "ansi_x3.4-1968", "iso646-us", "us"]

    static let wordRe = re("=\\?([^?\\s]+)\\?([BbQq])\\?([^?\\s]*)\\?=")
    static let hex2 = re("^[0-9A-Fa-f]{2}$")

    /// RFC 2047 encoded words, as iconv_mime_decode(CONTINUE_ON_ERROR) reads
    /// them (PHP 8.3 on glibc), quirks included (email-digest.js 1.3, F23): B is
    /// decoded leniently (php_base64_decode non-strict); a Q word with a bad
    /// escape or an unknown charset is kept as written; a word whose bytes the
    /// charset cannot take (invalid UTF-8, US-ASCII past 0x7F, windows-1252's
    /// undefined bytes) is kept as written too, and when it ends the value it
    /// loses its final '='.
    static func decodeHeaderValue(_ value: String) -> String {
        if value.isEmpty || !value.contains("=?") { return value }
        let ns = value as NSString
        var out = ""
        var last = 0
        var prevWasWord = false
        for m in wordRe.matches(in: value, range: NSRange(location: 0, length: ns.length)) {
            let between = ns.substring(with: NSRange(location: last, length: m.range.location - last))
            if !(prevWasWord && between.unicodeScalars.allSatisfy({ " \t\r\n".unicodeScalars.contains($0) })) {
                out += between
            }
            let whole = ns.substring(with: m.range)
            let end = m.range.location + m.range.length
            switch decodeWord(ns.substring(with: m.range(at: 1)), ns.substring(with: m.range(at: 2)),
                              ns.substring(with: m.range(at: 3))) {
            case .text(let t):
                out += t
                prevWasWord = true
            case .undecodable:
                out += whole
                prevWasWord = false
            case .unconvertible:
                out += end == ns.length ? String(whole.dropLast()) : whole
                prevWasWord = false
            }
            last = end
        }
        return out + ns.substring(from: last)
    }

    enum Word { case text(String), undecodable, unconvertible }

    static let b64Alphabet: [Character: UInt8] = {
        var t: [Character: UInt8] = [:]
        for (i, c) in "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/".enumerated() { t[c] = UInt8(i) }
        return t
    }()

    /// php_base64_decode(), non-strict: '=' and anything outside the alphabet skipped.
    static func phpBase64(_ text: String) -> [UInt8] {
        var out: [UInt8] = []
        var i = 0
        var cur: UInt8 = 0
        for ch in text {
            guard ch != "=", let v = b64Alphabet[ch] else { continue }
            switch i % 4 {
            case 0: cur = (v << 2) & 0xFF
            case 1: out.append(cur | (v >> 4)); cur = ((v & 0x0F) << 4) & 0xFF
            case 2: out.append(cur | (v >> 2)); cur = ((v & 0x03) << 6) & 0xFF
            default: out.append(cur | v)
            }
            i += 1
        }
        return out
    }

    static let cp1252Labels: Set<String> = ["windows-1252", "cp1252", "x-cp1252"]
    static let cp1252Undefined: Set<UInt8> = [0x81, 0x8D, 0x8F, 0x90, 0x9D]

    static func decodeWord(_ charset: String, _ enc: String, _ text: String) -> Word {
        var bytes: [UInt8]
        if enc == "B" || enc == "b" {
            bytes = phpBase64(text)
        } else {
            let q = sc(text.replacingOccurrences(of: "_", with: " "))
            bytes = []
            var j = 0
            while j < q.count {
                let ch = q[j]
                if ch == "=" {
                    // A lone '=' ending the text is a soft break: iconv drops it.
                    if j == q.count - 1 { break }
                    guard j + 2 < q.count, isHex(q[j + 1]), isHex(q[j + 2]) else { return .undecodable }
                    bytes.append(UInt8(str([q[j + 1], q[j + 2]]), radix: 16)!)
                    j += 3
                    continue
                }
                // JavaScript's charCodeAt & 0xFF: one UTF-16 unit at a time.
                for unit in String(ch).utf16 { bytes.append(UInt8(truncatingIfNeeded: unit)) }
                j += 1
            }
        }
        let label = charset.components(separatedBy: "*")[0].trimmingCharacters(in: .whitespaces).lowercased()
        if latin1Labels.contains(label) { return .text(MailMime.latin1(bytes)) }
        if asciiLabels.contains(label), bytes.contains(where: { $0 > 0x7F }) { return .unconvertible }
        if cp1252Labels.contains(label), bytes.contains(where: { cp1252Undefined.contains($0) }) { return .unconvertible }
        // Bare UTF-16: iconv honours a byte-order mark either way round and
        // reads little-endian without one.
        if label == "utf-16" && bytes.count >= 2 {
            if bytes[0] == 0xFE && bytes[1] == 0xFF {
                guard let t = String(bytes: bytes.dropFirst(2), encoding: .utf16BigEndian) else { return .unconvertible }
                return .text(t)
            }
            if bytes[0] == 0xFF && bytes[1] == 0xFE { bytes.removeFirst(2) }
        }
        guard let e = MailMime.encoding(for: label) else { return .undecodable }
        if e == .windowsCP1252 { return .text(MailMime.windows1252(bytes)) }
        guard let t = String(bytes: bytes, encoding: e) else { return .unconvertible }
        return .text(t)
    }

    static func headerBlockLines(_ raw: String) -> [String] {
        let norm = raw.replacingOccurrences(of: "\r\n", with: "\n")
        let block = norm.range(of: "\n\n").map { String(norm[..<$0.lowerBound]) } ?? norm
        return block.components(separatedBy: "\n")
    }

    /// The first occurrence of a header's unfolded value, or nil.
    static func extractHeader(_ raw: String, _ name: String) -> String? {
        let want = asciiLower(name)
        var collecting = false
        var current = ""
        for line in headerBlockLines(raw) {
            if let f = line.unicodeScalars.first, f == " " || f == "\t" {
                if collecting { current += " " + trim(line) }
                continue
            }
            if collecting { return trim(current) }
            guard let colon = line.firstIndex(of: ":") else { continue }
            if asciiLower(trim(String(line[..<colon]))) == want {
                collecting = true
                current = trim(String(line[line.index(after: colon)...]))
            }
        }
        return collecting ? trim(current) : nil
    }

    /// Every occurrence, unfolded.
    static func extractHeaders(_ raw: String, _ name: String) -> [String] {
        let want = asciiLower(name)
        var values: [String] = []
        var collecting = false
        var current = ""
        for line in headerBlockLines(raw) {
            if let f = line.unicodeScalars.first, f == " " || f == "\t" {
                if collecting { current += " " + trim(line) }
                continue
            }
            if collecting { values.append(current); collecting = false; current = "" }
            guard let colon = line.firstIndex(of: ":") else { continue }
            if asciiLower(trim(String(line[..<colon]))) == want {
                collecting = true
                current = trim(String(line[line.index(after: colon)...]))
            }
        }
        if collecting { values.append(current) }
        return values
    }

    static let asciiSpaces = re(sASCII + "+")
    static let resultRe = re("^([A-Za-z][A-Za-z0-9-]*)" + sASCII + "*=" + sASCII + "*([A-Za-z0-9]+)")
    static let propRes: [NSRegularExpression] = ["header\\.d", "header\\.i"].map {
        re("\\b" + $0 + sASCII + "*=" + sASCII + "*\"?([^\";" + String(sASCII.dropFirst().dropLast()) + "]+)\"?",
           [.caseInsensitive])
    }

    /// The DKIM d= from our own Authentication-Results stamp.
    static func dkimDomain(_ raw: String?, _ authservID: String) -> String {
        guard let raw else { return "" }
        let ours = asciiLower(trim(authservID))
        if ours.isEmpty { return "" }
        var domain: String? = nil
        for line in extractHeaders(raw, "authentication-results") {
            let segs = line.components(separatedBy: ";").map { trim($0) }.filter { !$0.isEmpty }
            guard let s0 = segs.first else { continue }
            let t0 = trim(s0)
            let first = asciiSpaces.stringByReplacingMatches(in: t0, range: NSRange(location: 0, length: (t0 as NSString).length),
                                                             withTemplate: "\u{0}").components(separatedBy: "\u{0}")
            if asciiLower(first.first ?? "") != ours { continue }
            for seg in segs.dropFirst() {
                let sg = trim(seg)
                let ns = sg as NSString
                guard let mm = resultRe.firstMatch(in: sg, range: NSRange(location: 0, length: ns.length)),
                      asciiLower(ns.substring(with: mm.range(at: 1))) == "dkim" else { continue }
                let result = asciiLower(ns.substring(with: mm.range(at: 2)))
                var d: String? = nil
                for pr in propRes {
                    if let pm = pr.firstMatch(in: sg, range: NSRange(location: 0, length: ns.length)) {
                        d = asciiLower(rtrim(trim(ns.substring(with: pm.range(at: 1))), ["."]))
                        break
                    }
                }
                if let d, domain == nil || result == "pass" { domain = d }
            }
        }
        return domain ?? ""
    }

    // MARK: The digest

    /// The opened columns a digest is built from (keys as the PHP side).
    public struct Input {
        public var raw: String?
        public var sender = "", recipient = "", receivedTime = "", subject = ""
        public var bodyPlain = "", bodyHTML = ""
        public var spf = "", dkim = "", dmarc = "", authservID = ""

        public init() {}
    }

    /// EmailSecurityDigest::buildFromColumns(), key for key.
    public static func build(_ c: Input) -> String {
        let raw = (c.raw?.isEmpty == false) ? c.raw : nil
        let fromRaw = raw != nil ? extractHeader(raw!, "from") : c.sender
        let replyRaw = raw != nil ? extractHeader(raw!, "reply-to") : nil
        let returnRaw = raw != nil ? extractHeader(raw!, "return-path") : nil
        let toRaw = raw != nil ? extractHeader(raw!, "to") : c.recipient
        let dateRaw = raw != nil ? extractHeader(raw!, "date") : c.receivedTime
        let subjectRaw = raw != nil ? extractHeader(raw!, "subject") : c.subject

        let from = decodeHeaderValue(fromRaw ?? "")
        let replyTo = (replyRaw.map { !trim($0).isEmpty } ?? false) ? decodeHeaderValue(replyRaw!) : "(none)"
        let returnPath = (returnRaw.map { !trim($0).isEmpty } ?? false) ? trim(returnRaw!, ["<", ">", " ", "\t"]) : "(none)"
        let to = decodeHeaderValue(toRaw ?? "")
        let date = !trim(dateRaw ?? "").isEmpty ? trim(dateRaw!) : "(unknown)"

        let spf = c.spf.isEmpty ? "unverified" : c.spf
        let dkim = c.dkim.isEmpty ? "unverified" : c.dkim
        let dmarc = c.dmarc.isEmpty ? "unverified" : c.dmarc
        let dDomain = dkimDomain(raw, c.authservID)

        let subj = collapse(decodeHeaderValue(subjectRaw ?? ""))
        let subjectText = capSize(subj.0, subjectCap)

        let sel = selectBody(c.bodyPlain, c.bodyHTML)
        let body = collapse(sel.0)
        let bodyText = capSize(body.0, bodyCap)
        let urls = extractUrls(c.bodyHTML, c.bodyPlain)

        var lines = ["=== EMAIL DIGEST ===", "FROM: " + from, "REPLY-TO: " + replyTo, "RETURN-PATH: " + returnPath,
                     "TO: " + to, "DATE: " + date,
                     "AUTHENTICATION: spf=\(spf) dkim=\(dkim) (d=\(dDomain.isEmpty ? "none" : dDomain)) dmarc=\(dmarc)",
                     "", "SUBJECT (decoded" + annotation(subj.1) + "):", subjectText, "",
                     "URLS FOUND (\(urls.count)):"]
        if urls.isEmpty {
            lines.append("(none found)")
        } else {
            let summary = domainSummary(urls)
            if !summary.isEmpty { lines.append("DOMAINS: " + summary) }
            let shown = urls.prefix(urlCap)
            for (i, e) in shown.enumerated() {
                var line = "\(i + 1). " + e.url
                if !e.text.isEmpty && e.text != e.url { line += " — link text: \"" + e.text + "\"" }
                lines.append(line)
            }
            if urls.count > shown.count { lines.append("(+\(urls.count - shown.count) more)") }
        }
        lines.append("")
        lines.append("BODY (" + sel.1 + ", decoded" + annotation(body.1) + "):")
        lines.append(bodyText)
        return lines.joined(separator: "\n")
    }

    /// EmailAttachmentDigest::buildFromManifest().
    public static func attachments(_ manifest: [JSONValue]) -> String {
        let parts = manifest.filter { e in
            guard case .object = e else { return false }
            // JavaScript truthiness of e.inline: only a falsy value is a regular part.
            switch e["inline"] {
            case .none, .some(.null), .some(.bool(false)): return true
            case .some(.number(let n)): return n == 0
            case .some(.string(let s)): return s.isEmpty
            default: return false
            }
        }
        if parts.isEmpty { return "" }
        var lines = ["ATTACHMENTS (\(parts.count)):"]
        var shown = 0
        for e in parts where shown < attMaxParts {
            shown += 1
            let fnv = e["filename"]
            var filename = trim((fnv == nil || fnv!.isNull) ? "" : (fnv!.stringValue ?? ""))
            filename = collapseAny(filename)
            if filename.isEmpty { filename = "(unnamed)" }
            else if mbLen(filename) > attFilenameCap { filename = mbSub(filename, attFilenameCap) }
            let ctv = e["content_type"]
            var type = trim((ctv == nil || ctv!.isNull) ? "" : (ctv!.stringValue ?? ""))
            if type.isEmpty { type = "application/octet-stream" }
            let size = e["size"].map { MailRuleMatch.intval($0) } ?? 0
            lines.append("\(shown). \(filename) — \(type), \(size) bytes")
        }
        if parts.count > shown { lines.append("(+\(parts.count - shown) more attachments)") }
        return lines.joined(separator: "\n")
    }

    /// WHITESPACE_RUN replaced by one space (no removal count).
    static func collapseAny(_ s: String) -> String { collapse(s).0 }

    // MARK: The untrusted-input envelope

    static let gap = "[" + String(sUCP.dropFirst().dropLast())
        + "\\x{00AD}\\x{200B}-\\x{200F}\\x{202A}-\\x{202E}\\x{2060}-\\x{2064}\\x{2066}-\\x{206F}\\x{FEFF}]*"
    static let marker = re("<<" + gap + "/?" + gap + "UNTRUSTED_", [.caseInsensitive])

    public static func neutralize(_ content: String) -> String { replace(content, marker, with: "[marker removed]") }

    public static func wrapBlock(_ content: String, nonce: String) -> String {
        "<<UNTRUSTED_\(nonce)>>\n" + neutralize(content) + "\n<</UNTRUSTED_\(nonce)>>"
    }
}
