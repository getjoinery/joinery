import Foundation
import JoineryKit

/// A model's answer turned into a verdict on the phone — a port of the
/// browser's `verdict-check.js` (PipelineRunner::parseVerdict(),
/// DescriptorValidator::coerce() and each device-capable job's
/// validateVerdict()), specs/fortress_mobile_apps.md § R13.
///
/// The same answer is accepted or refused with the same words whether the
/// server, a browser or this phone read it: a refusal's message is what the one
/// retry feeds back to the model. Any <think>…</think> block is dropped, the
/// FIRST balanced {...} object is taken, it is coerced against the job's verdict
/// descriptor with PHP's rules (a JSON 8.0 is an integer, a numeric string is
/// too, lengths in code points), and the job's cross-field rule runs last.
public enum VerdictCheck {
    public enum Result: Equatable {
        case verdict(JSONValue)
        case error(String)
    }

    struct Invalid: Error { let message: String }

    static let phpTrim: Set<Unicode.Scalar> = [" ", "\t", "\n", "\r", "\0", "\u{0B}"]

    static func trim(_ s: String) -> String { EmailDigest.trim(s, phpTrim) }

    /// PipelineRunner::extractFirstJsonObject().
    static func extractFirstJsonObject(_ text: String) -> String? {
        let u = Array(text.unicodeScalars)
        guard let start = u.firstIndex(of: "{") else { return nil }
        var depth = 0, inString = false, escaped = false
        var i = start
        while i < u.count {
            let ch = u[i]
            defer { i += 1 }
            if escaped { escaped = false; continue }
            if ch == "\\" { escaped = true; continue }
            if ch == "\"" { inString.toggle(); continue }
            if inString { continue }
            if ch == "{" { depth += 1 }
            else if ch == "}" {
                depth -= 1
                if depth == 0 { return EmailDigest.str(u[start...i]) }
            }
        }
        return nil
    }

    static func isArrayish(_ v: JSONValue?) -> Bool {
        switch v { case .some(.object), .some(.array): return true; default: return false }
    }

    static func isInteger(_ n: Double) -> Bool { n.isFinite && n == n.rounded(.towardZero) }

    static let intRe = try! NSRegularExpression(pattern: "^-?\\d+$")
    static let numericRe = try! NSRegularExpression(
        pattern: "^[ \\t\\n\\r\\x0B\\f]*[+-]?(\\d+(\\.\\d*)?|\\.\\d+)([eE][+-]?\\d+)?[ \\t\\n\\r\\x0B\\f]*$")

    static func test(_ re: NSRegularExpression, _ s: String) -> Bool {
        re.firstMatch(in: s, range: NSRange(location: 0, length: (s as NSString).length)) != nil
    }

    static func phpString(_ v: JSONValue) -> String {
        switch v {
        case .bool(let b): return b ? "1" : ""
        case .number(let n): return isInteger(n) && abs(n) < 1e21 ? String(Int64(n)) : String(n)
        default: return v.stringValue ?? ""
        }
    }

    static func num(_ v: JSONValue?) -> Double? {
        switch v { case .some(.number(let n)): return n; case .some(.string(let s)): return Double(s); default: return nil }
    }

    /// DescriptorValidator::coerceValue().
    static func coerceValue(_ value: JSONValue, _ type: String, _ field: String, _ label: String) throws -> JSONValue {
        switch type {
        case "int", "integer":
            if case .number(let n) = value, isInteger(n) { return .number(n) }
            if case .string(let s) = value, test(intRe, s), let n = Double(s) { return .number(n.rounded(.towardZero)) }
            throw Invalid(message: "\(label) (\(field)) must be an integer.")
        case "float", "number":
            if case .number = value { return value }
            if case .string(let s) = value, test(numericRe, s),
               let n = Double(s.trimmingCharacters(in: .whitespacesAndNewlines)) { return .number(n) }
            throw Invalid(message: "\(label) (\(field)) must be a number.")
        case "bool", "boolean":
            if case .bool = value { return value }
            if value == .number(1) || value == .string("1") || value == .string("true") || value == .string("on") { return .bool(true) }
            if value == .number(0) || value == .string("0") || value == .string("false") || value == .string("off") { return .bool(false) }
            throw Invalid(message: "\(label) (\(field)) must be a boolean.")
        case "object":
            guard isArrayish(value) else { throw Invalid(message: "\(label) (\(field)) must be an object.") }
            return value
        default:
            switch value {
            case .string: return value
            case .number, .bool: return .string(phpString(value))
            default: throw Invalid(message: "\(label) (\(field)) must be a string.")
            }
        }
    }

    /// DescriptorValidator::checkBounds().
    static func checkBounds(_ value: JSONValue, _ spec: JSONValue, _ type: String, _ field: String, _ label: String) throws {
        if let en = spec["enum"]?.arrayValue, !en.isEmpty, !en.contains(value) {
            let list = en.map { phpString($0) }.joined(separator: ", ")
            throw Invalid(message: "\(label) (\(field)) must be one of: \(list).")
        }
        if ["int", "integer", "float", "number"].contains(type), case .number(let v) = value {
            if let mn = spec["min"], !mn.isNull, let m = num(mn), v < m {
                throw Invalid(message: "\(label) (\(field)) must be at least \(phpString(mn)).")
            }
            if let mx = spec["max"], !mx.isNull, let m = num(mx), v > m {
                throw Invalid(message: "\(label) (\(field)) must be at most \(phpString(mx)).")
            }
        }
        if ["string", "text", "password"].contains(type), let ml = spec["max_length"], !ml.isNull,
           case .string(let s) = value, s.unicodeScalars.count > MailRuleMatch.intval(ml) {
            throw Invalid(message: "\(label) (\(field)) must be at most \(phpString(ml)) characters.")
        }
    }

    /// DescriptorValidator::coerce().
    static func coerce(_ schema: JSONValue?, _ input: JSONValue) throws -> JSONValue {
        guard case .object(let fields) = schema ?? .null else { return .object([]) }
        var out: [(key: String, value: JSONValue)] = []
        for (field, spec) in fields {
            guard isArrayish(spec) else { continue }
            let type = spec["type"]?.stringValue ?? "string"
            let required = spec["required"]?.boolValue ?? false
            let label = spec["label"]?.stringValue ?? field
            var present = false
            if case .object(let pairs) = input { present = pairs.contains { $0.key == field } }
            let value = present ? (input[field] ?? .null) : .null
            let empty: Bool = {
                if !present || value.isNull || value == .string("") { return true }
                if type == "array", let a = value.arrayValue, a.isEmpty, case .array = value { return true }
                return false
            }()
            if empty {
                if required { throw Invalid(message: "Missing required field: \(label) (\(field)).") }
                if case .object(let sp) = spec, let d = sp.first(where: { $0.key == "default" }) {
                    out.append((field, d.value))
                }
                continue
            }
            if type == "array" {
                out.append((field, try coerceArray(value, spec, field, label)))
                continue
            }
            let c = try coerceValue(value, type, field, label)
            try checkBounds(c, spec, type, field, label)
            out.append((field, c))
        }
        return .object(out)
    }

    static func coerceArray(_ value: JSONValue, _ spec: JSONValue, _ field: String, _ label: String) throws -> JSONValue {
        guard case .array(let items) = value else { throw Invalid(message: "\(label) (\(field)) must be an array.") }
        if let mi = spec["max_items"], !mi.isNull, items.count > MailRuleMatch.intval(mi) {
            throw Invalid(message: "\(label) (\(field)) must have at most \(phpString(mi)) items.")
        }
        let itemSchema = isArrayish(spec["items"]) ? spec["items"]! : .object([])
        let scalarItems: Bool = { if case .some(.string) = itemSchema["type"] { return true }; return false }()
        return .array(try items.enumerated().map { i, item in
            if scalarItems {
                let t = itemSchema["type"]!.stringValue!
                let c = try coerceValue(item, t, "\(field)[\(i)]", "\(label) #\(i)")
                try checkBounds(c, itemSchema, t, "\(field)[\(i)]", "\(label) #\(i)")
                return c
            }
            guard isArrayish(item) else { throw Invalid(message: "\(label) (\(field))[\(i)] must be an object.") }
            return try coerce(itemSchema, item)
        })
    }

    /// Each device-capable job's validateVerdict().
    static func validateJob(_ jobID: String, _ verdict: JSONValue) throws {
        guard jobID == "email_security_scan" else { return }
        var score: Double = -1
        if case .some(.number(let n)) = verdict["score"] { score = n }
        let expected = score >= 7 ? "dangerous" : (score >= 5 ? "caution" : "safe")
        let word = verdict["verdict"].map { phpString($0) } ?? ""
        if word != expected {
            throw Invalid(message: "verdict ('\(word)') does not match the required band for score \(phpString(.number(score))) ('\(expected)'). 0-4=safe, 5-6=caution, 7-10=dangerous.")
        }
    }

    static let thinkRe = try! NSRegularExpression(pattern: "<think>[\\s\\S]*?</think>")

    public static func parse(_ text: String, descriptor: JSONValue?, jobID: String) -> Result {
        let noThink = thinkRe.stringByReplacingMatches(in: text, range: NSRange(location: 0, length: (text as NSString).length),
                                                       withTemplate: "")
        guard let json = extractFirstJsonObject(trim(noThink)) else {
            return .error("no JSON object found in the model response")
        }
        guard let decoded = try? JSONValue.parse(json), isArrayish(decoded) else {
            return .error("model response was not valid JSON: Syntax error")
        }
        do {
            let verdict = try coerce(descriptor?["input"], decoded)
            try validateJob(jobID, verdict)
            return .verdict(verdict)
        } catch let e as Invalid {
            return .error(e.message)
        } catch {
            return .error(error.localizedDescription)
        }
    }

    public static func retryMessage(_ error: String) -> String {
        "That response was invalid: " + error + "\n\nRespond again with ONLY the corrected JSON object."
    }
}
