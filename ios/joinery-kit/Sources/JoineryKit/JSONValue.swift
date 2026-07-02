import Foundation

/// An order-preserving JSON value.
///
/// Form definitions carry `options` objects whose key order is meaningful —
/// the server emits options in display order and the web renders them that
/// way. Foundation's `JSONDecoder`/`JSONSerialization` hand back unordered
/// dictionaries, so JoineryKit parses API JSON with its own small parser and
/// represents objects as ordered key/value pair arrays.
///
/// Also absorbs a server quirk: an empty `data` serializes as `[]` (PHP empty
/// array), a populated one as `{}`-style object. Both decode fine here and
/// `objectValue` treats an empty array as an empty object.
public enum JSONValue: Equatable, Sendable {
    case string(String)
    case number(Double)
    case bool(Bool)
    case null
    indirect case array([JSONValue])
    indirect case object([(key: String, value: JSONValue)])

    public static func == (lhs: JSONValue, rhs: JSONValue) -> Bool {
        switch (lhs, rhs) {
        case let (.string(a), .string(b)): return a == b
        case let (.number(a), .number(b)): return a == b
        case let (.bool(a), .bool(b)): return a == b
        case (.null, .null): return true
        case let (.array(a), .array(b)): return a == b
        case let (.object(a), .object(b)):
            return a.count == b.count && zip(a, b).allSatisfy { $0.key == $1.key && $0.value == $1.value }
        default: return false
        }
    }

    // MARK: Accessors

    public var stringValue: String? {
        switch self {
        case .string(let s): return s
        case .number(let n): return n == n.rounded() && abs(n) < 1e15 ? String(Int64(n)) : String(n)
        case .bool(let b): return b ? "1" : "0"
        default: return nil
        }
    }

    public var intValue: Int? {
        switch self {
        case .number(let n): return Int(n)
        case .string(let s): return Int(s)
        case .bool(let b): return b ? 1 : 0
        default: return nil
        }
    }

    public var doubleValue: Double? {
        switch self {
        case .number(let n): return n
        case .string(let s): return Double(s)
        default: return nil
        }
    }

    public var boolValue: Bool? {
        switch self {
        case .bool(let b): return b
        case .number(let n): return n != 0
        case .string(let s):
            if s == "1" || s.lowercased() == "true" { return true }
            if s == "0" || s.lowercased() == "false" || s.isEmpty { return false }
            return nil
        default: return nil
        }
    }

    public var arrayValue: [JSONValue]? {
        if case .array(let a) = self { return a }
        return nil
    }

    /// Object pairs in document order. An empty JSON array also reads as an
    /// empty object (the PHP empty-array quirk).
    public var objectValue: [(key: String, value: JSONValue)]? {
        switch self {
        case .object(let pairs): return pairs
        case .array(let a) where a.isEmpty: return []
        default: return nil
        }
    }

    public var isNull: Bool {
        if case .null = self { return true }
        return false
    }

    /// First value for a key (document order).
    public subscript(key: String) -> JSONValue? {
        guard let pairs = objectValue else { return nil }
        return pairs.first(where: { $0.key == key })?.value
    }

    // MARK: Encoding

    /// Serialize back to compact JSON text (object order preserved).
    public func encoded() -> String {
        switch self {
        case .string(let s): return JSONValue.encodeString(s)
        case .number(let n):
            if n == n.rounded() && abs(n) < 1e15 { return String(Int64(n)) }
            return String(n)
        case .bool(let b): return b ? "true" : "false"
        case .null: return "null"
        case .array(let a): return "[" + a.map { $0.encoded() }.joined(separator: ",") + "]"
        case .object(let pairs):
            return "{" + pairs.map { JSONValue.encodeString($0.key) + ":" + $0.value.encoded() }.joined(separator: ",") + "}"
        }
    }

    public func encodedData() -> Data {
        Data(encoded().utf8)
    }

    private static func encodeString(_ s: String) -> String {
        var out = "\""
        for scalar in s.unicodeScalars {
            switch scalar {
            case "\"": out += "\\\""
            case "\\": out += "\\\\"
            case "\n": out += "\\n"
            case "\r": out += "\\r"
            case "\t": out += "\\t"
            default:
                if scalar.value < 0x20 {
                    out += String(format: "\\u%04x", scalar.value)
                } else {
                    out.unicodeScalars.append(scalar)
                }
            }
        }
        return out + "\""
    }

    // MARK: Parsing

    public enum ParseError: Error {
        case syntax(String)
    }

    public static func parse(_ data: Data) throws -> JSONValue {
        guard let text = String(data: data, encoding: .utf8) else {
            throw ParseError.syntax("not UTF-8")
        }
        return try parse(text)
    }

    public static func parse(_ text: String) throws -> JSONValue {
        var parser = Parser(text: Array(text.unicodeScalars))
        let value = try parser.parseValue()
        parser.skipWhitespace()
        guard parser.isAtEnd else { throw ParseError.syntax("trailing characters") }
        return value
    }

    private struct Parser {
        let text: [Unicode.Scalar]
        var pos = 0

        var isAtEnd: Bool { pos >= text.count }

        mutating func skipWhitespace() {
            while pos < text.count {
                let c = text[pos]
                if c == " " || c == "\t" || c == "\n" || c == "\r" { pos += 1 } else { break }
            }
        }

        mutating func parseValue() throws -> JSONValue {
            skipWhitespace()
            guard !isAtEnd else { throw ParseError.syntax("unexpected end") }
            switch text[pos] {
            case "{": return try parseObject()
            case "[": return try parseArray()
            case "\"": return .string(try parseString())
            case "t":
                try expect("true"); return .bool(true)
            case "f":
                try expect("false"); return .bool(false)
            case "n":
                try expect("null"); return .null
            default: return try parseNumber()
            }
        }

        mutating func expect(_ word: String) throws {
            for scalar in word.unicodeScalars {
                guard pos < text.count, text[pos] == scalar else {
                    throw ParseError.syntax("expected \(word)")
                }
                pos += 1
            }
        }

        mutating func parseObject() throws -> JSONValue {
            pos += 1 // {
            var pairs: [(key: String, value: JSONValue)] = []
            skipWhitespace()
            if pos < text.count, text[pos] == "}" { pos += 1; return .object(pairs) }
            while true {
                skipWhitespace()
                guard pos < text.count, text[pos] == "\"" else { throw ParseError.syntax("expected object key") }
                let key = try parseString()
                skipWhitespace()
                guard pos < text.count, text[pos] == ":" else { throw ParseError.syntax("expected ':'") }
                pos += 1
                let value = try parseValue()
                pairs.append((key: key, value: value))
                skipWhitespace()
                guard pos < text.count else { throw ParseError.syntax("unterminated object") }
                if text[pos] == "," { pos += 1; continue }
                if text[pos] == "}" { pos += 1; return .object(pairs) }
                throw ParseError.syntax("expected ',' or '}'")
            }
        }

        mutating func parseArray() throws -> JSONValue {
            pos += 1 // [
            var items: [JSONValue] = []
            skipWhitespace()
            if pos < text.count, text[pos] == "]" { pos += 1; return .array(items) }
            while true {
                let value = try parseValue()
                items.append(value)
                skipWhitespace()
                guard pos < text.count else { throw ParseError.syntax("unterminated array") }
                if text[pos] == "," { pos += 1; continue }
                if text[pos] == "]" { pos += 1; return .array(items) }
                throw ParseError.syntax("expected ',' or ']'")
            }
        }

        mutating func parseString() throws -> String {
            pos += 1 // opening quote
            var scalars = String.UnicodeScalarView()
            while pos < text.count {
                let c = text[pos]
                if c == "\"" { pos += 1; return String(scalars) }
                if c == "\\" {
                    pos += 1
                    guard pos < text.count else { break }
                    let esc = text[pos]
                    switch esc {
                    case "\"": scalars.append("\""); pos += 1
                    case "\\": scalars.append("\\"); pos += 1
                    case "/": scalars.append("/"); pos += 1
                    case "b": scalars.append("\u{08}"); pos += 1
                    case "f": scalars.append("\u{0C}"); pos += 1
                    case "n": scalars.append("\n"); pos += 1
                    case "r": scalars.append("\r"); pos += 1
                    case "t": scalars.append("\t"); pos += 1
                    case "u":
                        pos += 1
                        let unit = try parseHex4()
                        if unit >= 0xD800 && unit <= 0xDBFF,
                           pos + 1 < text.count, text[pos] == "\\", text[pos + 1] == "u" {
                            pos += 2
                            let low = try parseHex4()
                            if low >= 0xDC00 && low <= 0xDFFF {
                                let combined = 0x10000 + ((unit - 0xD800) << 10) + (low - 0xDC00)
                                if let scalar = Unicode.Scalar(combined) { scalars.append(scalar) }
                            } else {
                                throw ParseError.syntax("invalid surrogate pair")
                            }
                        } else if let scalar = Unicode.Scalar(unit) {
                            scalars.append(scalar)
                        }
                    default:
                        throw ParseError.syntax("bad escape")
                    }
                } else {
                    scalars.append(c)
                    pos += 1
                }
            }
            throw ParseError.syntax("unterminated string")
        }

        mutating func parseHex4() throws -> Int {
            var value = 0
            for _ in 0..<4 {
                guard pos < text.count, let digit = text[pos].hexDigitValue else {
                    throw ParseError.syntax("bad \\u escape")
                }
                value = value * 16 + digit
                pos += 1
            }
            return value
        }

        mutating func parseNumber() throws -> JSONValue {
            let start = pos
            if pos < text.count, text[pos] == "-" { pos += 1 }
            while pos < text.count, isNumberScalar(text[pos]) { pos += 1 }
            guard pos > start else { throw ParseError.syntax("expected value") }
            let literal = String(String.UnicodeScalarView(text[start..<pos]))
            guard let n = Double(literal) else { throw ParseError.syntax("bad number \(literal)") }
            return .number(n)
        }

        private func isNumberScalar(_ c: Unicode.Scalar) -> Bool {
            return (c >= "0" && c <= "9") || c == "." || c == "e" || c == "E" || c == "+" || c == "-"
        }
    }
}

private extension Unicode.Scalar {
    var hexDigitValue: Int? {
        switch self {
        case "0"..."9": return Int(value - 0x30)
        case "a"..."f": return Int(value - 0x61 + 10)
        case "A"..."F": return Int(value - 0x41 + 10)
        default: return nil
        }
    }
}
