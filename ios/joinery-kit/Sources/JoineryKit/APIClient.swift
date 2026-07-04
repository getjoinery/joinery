import Foundation

/// The one HTTP chokepoint. Every JoineryKit request — auth, forms, actions —
/// goes through here, so client headers, key headers, idempotency keys, and
/// error-envelope mapping happen exactly once.
public final class APIClient: @unchecked Sendable {
    public let config: JoineryConfig

    /// Current session key pair, nil when signed out. Guarded by a lock —
    /// written from the main actor, read on request tasks.
    private let credentialsLock = NSLock()
    private var _credentials: APICredentials?

    public func setCredentials(_ credentials: APICredentials?) {
        credentialsLock.lock()
        _credentials = credentials
        credentialsLock.unlock()
    }

    public var credentials: APICredentials? {
        credentialsLock.lock()
        defer { credentialsLock.unlock() }
        return _credentials
    }

    /// Fired on any 426 UpgradeRequired so the app can flip into the blocking
    /// upgrade screen no matter which call tripped it.
    public var upgradeRequiredHandler: (@Sendable (String) -> Void)?

    /// Fired when an authenticated request comes back 401 — the key was
    /// revoked out from under us (App Sessions page, password change).
    public var sessionInvalidatedHandler: (@Sendable () -> Void)?

    private let urlSession: URLSession

    public init(config: JoineryConfig, urlSession: URLSession = .shared) {
        self.config = config
        self.urlSession = urlSession
    }

    // MARK: Requests

    /// Perform a request and return the parsed success envelope.
    /// - `body` non-nil sends a JSON body.
    /// - `authenticated` attaches the session key headers (and treats their
    ///   absence as a programmer error caught by the 401 that follows).
    /// - `idempotencyKey` is attached verbatim when given — pass a fresh UUID
    ///   per logical mutating operation, reuse only for retries of that
    ///   operation (docs/api.md § Idempotent writes).
    @discardableResult
    public func request(
        _ method: String,
        _ path: String,
        query: [URLQueryItem] = [],
        body: JSONValue? = nil,
        authenticated: Bool = true,
        idempotencyKey: String? = nil
    ) async throws -> JSONValue {
        var components = URLComponents(url: config.baseURL, resolvingAgainstBaseURL: false)!
        components.path = path
        if !query.isEmpty { components.queryItems = query }
        var request = URLRequest(url: components.url!)
        request.httpMethod = method
        request.timeoutInterval = 30

        applyStandardHeaders(to: &request, authenticated: authenticated, idempotencyKey: idempotencyKey)
        if let body {
            request.setValue("application/json", forHTTPHeaderField: "Content-Type")
            request.httpBody = body.encodedData()
        }
        return try await perform(request, authenticated: authenticated)
    }

    /// The headers every request carries — hyphen form because proxy/FPM stacks
    /// drop underscore header names (docs/api.md). Session-key headers ride only
    /// on authenticated calls; their absence surfaces as the 401 that follows.
    private func applyStandardHeaders(to request: inout URLRequest, authenticated: Bool, idempotencyKey: String?) {
        request.setValue("application/json", forHTTPHeaderField: "Accept")
        request.setValue(config.clientApp, forHTTPHeaderField: "client-app")
        request.setValue(config.clientVersion, forHTTPHeaderField: "client-version")
        if authenticated, let credentials = self.credentials {
            request.setValue(credentials.publicKey, forHTTPHeaderField: "public-key")
            request.setValue(credentials.secretKey, forHTTPHeaderField: "secret-key")
        }
        if let idempotencyKey {
            request.setValue(idempotencyKey, forHTTPHeaderField: "Idempotency-Key")
        }
    }

    /// Run a fully-built request and map the response into the success envelope
    /// or a typed error. Shared by the JSON and multipart submit paths.
    private func perform(_ request: URLRequest, authenticated: Bool) async throws -> JSONValue {
        let data: Data
        let response: URLResponse
        do {
            (data, response) = try await urlSession.data(for: request)
        } catch {
            throw JoineryAPIError.network(underlying: error)
        }
        guard let http = response as? HTTPURLResponse else {
            throw JoineryAPIError.malformedResponse
        }

        let json: JSONValue
        do {
            json = try JSONValue.parse(data)
        } catch {
            throw JoineryAPIError.malformedResponse
        }

        if http.statusCode >= 400 {
            throw mapError(status: http.statusCode, envelope: json, authenticated: authenticated)
        }
        return json
    }

    /// GET a form definition: `/api/v1/form/{action}`.
    /// Sessionless forms (password resets, register) pass `authenticated: false`.
    public func formDefinition(
        action: String,
        query: [URLQueryItem] = [],
        authenticated: Bool = true
    ) async throws -> FormDefinition {
        let envelope = try await request("GET", "/api/v1/form/\(action)", query: query, authenticated: authenticated)
        guard let definition = FormDefinition(data: envelope["data"]) else {
            throw JoineryAPIError.malformedResponse
        }
        return definition
    }

    /// Submit an action: `POST /api/v1/action/{action}`. Mutating, so an
    /// idempotency key is generated automatically unless one is supplied.
    @discardableResult
    public func submitAction(
        _ action: String,
        body: JSONValue,
        authenticated: Bool = true,
        idempotencyKey: String? = nil
    ) async throws -> JSONValue {
        try await request(
            "POST", "/api/v1/action/\(action)",
            body: body,
            authenticated: authenticated,
            idempotencyKey: idempotencyKey ?? UUID().uuidString
        )
    }

    /// Submit an action as `multipart/form-data`: `POST /api/v1/action/{action}`,
    /// with `fields` as text parts and `files` as file parts — for uploads the
    /// JSON body can't carry (chat attachments). The action pipeline reads the
    /// text parts as `$_POST` and the file parts as `$_FILES`. Mutating, so an
    /// idempotency key is generated unless supplied; server-side it hashes over
    /// the (empty) raw body, so a multipart send dedupes retries by key alone.
    @discardableResult
    public func submitMultipart(
        _ action: String,
        fields: [(key: String, value: String)],
        files: [MultipartFile],
        authenticated: Bool = true,
        idempotencyKey: String? = nil
    ) async throws -> JSONValue {
        var components = URLComponents(url: config.baseURL, resolvingAgainstBaseURL: false)!
        components.path = "/api/v1/action/\(action)"
        var request = URLRequest(url: components.url!)
        request.httpMethod = "POST"
        // Uploads can be several MB — allow more headroom than a JSON call.
        request.timeoutInterval = 60
        applyStandardHeaders(to: &request, authenticated: authenticated,
                             idempotencyKey: idempotencyKey ?? UUID().uuidString)

        let boundary = "JoineryBoundary-\(UUID().uuidString)"
        request.setValue("multipart/form-data; boundary=\(boundary)", forHTTPHeaderField: "Content-Type")
        request.httpBody = Self.multipartBody(boundary: boundary, fields: fields, files: files)
        return try await perform(request, authenticated: authenticated)
    }

    /// Assemble a `multipart/form-data` body: text field parts first, then file
    /// parts. Filenames are sanitized so a quote or newline can't break the part
    /// header. Internal so the encoder can be unit-tested directly.
    static func multipartBody(
        boundary: String,
        fields: [(key: String, value: String)],
        files: [MultipartFile]
    ) -> Data {
        var body = Data()
        for field in fields {
            body.appendString("--\(boundary)\r\n")
            body.appendString("Content-Disposition: form-data; name=\"\(field.key)\"\r\n\r\n")
            body.appendString("\(field.value)\r\n")
        }
        for file in files {
            let safeName = file.filename
                .replacingOccurrences(of: "\"", with: "'")
                .replacingOccurrences(of: "\r", with: " ")
                .replacingOccurrences(of: "\n", with: " ")
            body.appendString("--\(boundary)\r\n")
            body.appendString("Content-Disposition: form-data; name=\"\(file.field)\"; filename=\"\(safeName)\"\r\n")
            body.appendString("Content-Type: \(file.mimeType)\r\n\r\n")
            body.append(file.data)
            body.appendString("\r\n")
        }
        body.appendString("--\(boundary)--\r\n")
        return body
    }

    // MARK: Error mapping

    // Internal (not private) so unit tests can exercise the mapping table.
    func mapError(status: Int, envelope: JSONValue, authenticated: Bool) -> JoineryAPIError {
        let errortype = envelope["errortype"]?.stringValue ?? ""
        let message = envelope["error"]?.stringValue ?? "Request failed."

        if status == 426 || errortype == "UpgradeRequired" {
            // SecurityError 426 is HTTPS-only enforcement; a shipped app is
            // always HTTPS, so any 426 in practice is the upgrade gate.
            if errortype != "SecurityError" {
                upgradeRequiredHandler?(message)
                return .upgradeRequired(message: message)
            }
        }
        if errortype == "RateLimitError" {
            return .rateLimited(message: message)
        }
        if errortype == "AuthenticationError" {
            if authenticated && status == 401 {
                sessionInvalidatedHandler?()
            }
            return .authentication(message: message, status: status)
        }
        if status == 422 {
            // ValidationError carries a field-keyed map; other 422s (model
            // save failures surface as ActionError) carry only the message.
            var fields: [String: String] = [:]
            if let pairs = envelope["validation_errors"]?.objectValue {
                for (key, value) in pairs { fields[key] = value.stringValue ?? "" }
            }
            return .validation(message: message, fieldErrors: fields)
        }
        return .server(errortype: errortype, message: message, status: status)
    }
}

/// One file part for a `multipart/form-data` action submit.
public struct MultipartFile: Sendable {
    /// The form field name, e.g. `attachments[]` (PHP folds the `[]` suffix into
    /// an `$_FILES['attachments']` array so several files share one field).
    public let field: String
    public let filename: String
    public let mimeType: String
    public let data: Data

    public init(field: String, filename: String, mimeType: String, data: Data) {
        self.field = field
        self.filename = filename
        self.mimeType = mimeType
        self.data = data
    }
}

private extension Data {
    mutating func appendString(_ string: String) {
        append(Data(string.utf8))
    }
}
