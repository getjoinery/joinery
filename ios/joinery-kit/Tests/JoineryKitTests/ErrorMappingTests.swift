import XCTest
@testable import JoineryKit

final class ErrorMappingTests: XCTestCase {

    private var client: APIClient {
        APIClient(config: JoineryConfig(
            baseURL: URL(string: "https://example.test")!,
            clientApp: "test-app",
            clientVersion: "0.0.1",
            appName: "Test"
        ))
    }

    private func envelope(_ json: String) throws -> JSONValue {
        try JSONValue.parse(json)
    }

    func testUpgradeRequiredMapsAndFiresHandler() throws {
        let c = client
        let fired = expectation(description: "upgrade handler")
        nonisolated(unsafe) var receivedMessage = ""
        c.upgradeRequiredHandler = { message in
            receivedMessage = message
            fired.fulfill()
        }
        let error = c.mapError(
            status: 426,
            envelope: try envelope(#"{"api_version":"1.0","errortype":"UpgradeRequired","error":"Please update","data":{}}"#),
            authenticated: false
        )
        wait(for: [fired], timeout: 1)
        guard case .upgradeRequired(let message) = error else {
            return XCTFail("expected upgradeRequired, got \(error)")
        }
        XCTAssertEqual(message, "Please update")
        XCTAssertEqual(receivedMessage, "Please update")
    }

    func testHTTPSSecurity426IsNotUpgrade() throws {
        let error = client.mapError(
            status: 426,
            envelope: try envelope(#"{"api_version":"1.0","errortype":"SecurityError","error":"HTTPS required","data":{}}"#),
            authenticated: false
        )
        if case .upgradeRequired = error {
            XCTFail("HTTPS-enforcement 426 must not render the upgrade screen")
        }
    }

    func testRateLimitMaps() throws {
        let error = client.mapError(
            status: 429,
            envelope: try envelope(#"{"api_version":"1.0","errortype":"RateLimitError","error":"Too many attempts","data":{}}"#),
            authenticated: false
        )
        guard case .rateLimited(let message) = error else {
            return XCTFail("expected rateLimited, got \(error)")
        }
        XCTAssertEqual(message, "Too many attempts")
    }

    func testLogin401DoesNotInvalidateSession() throws {
        let c = client
        nonisolated(unsafe) var invalidated = false
        c.sessionInvalidatedHandler = { invalidated = true }
        let error = c.mapError(
            status: 401,
            envelope: try envelope(#"{"api_version":"1.0","errortype":"AuthenticationError","error":"Invalid credentials","data":{}}"#),
            authenticated: false
        )
        guard case .authentication = error else {
            return XCTFail("expected authentication, got \(error)")
        }
        XCTAssertFalse(invalidated, "an unauthenticated 401 (login) must not tear down the session")
    }

    func testAuthenticated401FiresSessionInvalidated() throws {
        let c = client
        let fired = expectation(description: "session invalidated")
        c.sessionInvalidatedHandler = { fired.fulfill() }
        _ = c.mapError(
            status: 401,
            envelope: try envelope(#"{"api_version":"1.0","errortype":"AuthenticationError","error":"Invalid key","data":{}}"#),
            authenticated: true
        )
        wait(for: [fired], timeout: 1)
    }

    func testValidation422WithFieldMap() throws {
        let error = client.mapError(
            status: 422,
            envelope: try envelope(#"{"api_version":"1.0","errortype":"ValidationError","error":"Fix the form","data":{},"validation_errors":{"usr_email":"Bad address"}}"#),
            authenticated: true
        )
        guard case .validation(let message, let fields) = error else {
            return XCTFail("expected validation, got \(error)")
        }
        XCTAssertEqual(message, "Fix the form")
        XCTAssertEqual(fields["usr_email"], "Bad address")
    }

    func testActionError422WithEmptyArrayData() throws {
        // Real capture: model-save failures 422 as ActionError with data:[]
        let raw = try fixture("validation_422.json")
        let wrapper = try JSONValue.parse(raw)
        let error = client.mapError(status: 422, envelope: wrapper["body"]!, authenticated: true)
        guard case .validation(let message, let fields) = error else {
            return XCTFail("expected validation for 422, got \(error)")
        }
        XCTAssertTrue(message.contains("usr_first_name"))
        XCTAssertTrue(fields.isEmpty)
    }

    func testUnknownServerErrorMaps() throws {
        let error = client.mapError(
            status: 500,
            envelope: try envelope(#"{"api_version":"1.0","errortype":"ActionError","error":"Boom","data":{}}"#),
            authenticated: true
        )
        guard case .server(let errortype, _, let status) = error else {
            return XCTFail("expected server, got \(error)")
        }
        XCTAssertEqual(errortype, "ActionError")
        XCTAssertEqual(status, 500)
    }
}
