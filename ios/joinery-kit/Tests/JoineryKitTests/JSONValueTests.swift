import XCTest
@testable import JoineryKit

final class JSONValueTests: XCTestCase {

    func testObjectOrderPreserved() throws {
        let json = #"{"zebra": 1, "apple": 2, "mango": 3}"#
        let value = try JSONValue.parse(json)
        let keys = value.objectValue?.map { $0.key }
        XCTAssertEqual(keys, ["zebra", "apple", "mango"])
    }

    func testEmptyArrayReadsAsEmptyObject() throws {
        // PHP serializes an empty data payload as [] — envelope consumers
        // must be able to treat it as an empty object.
        let value = try JSONValue.parse("[]")
        XCTAssertEqual(value.objectValue?.count, 0)
        XCTAssertNil(value["anything"])
    }

    func testStringEscapes() throws {
        let json = #"{"a": "line\nbreak \"quoted\" back\\slash é 😀"}"#
        let value = try JSONValue.parse(json)
        XCTAssertEqual(value["a"]?.stringValue, "line\nbreak \"quoted\" back\\slash é 😀")
    }

    func testNumbersAndBools() throws {
        let value = try JSONValue.parse(#"{"i": 42, "f": 3.5, "neg": -7, "t": true, "f2": false, "n": null}"#)
        XCTAssertEqual(value["i"]?.intValue, 42)
        XCTAssertEqual(value["f"]?.doubleValue, 3.5)
        XCTAssertEqual(value["neg"]?.intValue, -7)
        XCTAssertEqual(value["t"]?.boolValue, true)
        XCTAssertEqual(value["f2"]?.boolValue, false)
        XCTAssertEqual(value["n"]?.isNull, true)
    }

    func testRoundTripPreservesOrder() throws {
        let json = #"{"b":{"z":1,"a":[true,null,"x"]},"a":"end"}"#
        let value = try JSONValue.parse(json)
        XCTAssertEqual(value.encoded(), json)
    }

    func testTrailingGarbageRejected() {
        XCTAssertThrowsError(try JSONValue.parse(#"{"a":1} extra"#))
    }

    func testRealFixtureParses() throws {
        let data = try fixture("form_register.json")
        let value = try JSONValue.parse(data)
        XCTAssertEqual(value["api_version"]?.stringValue, "1.0")
        XCTAssertNotNil(value["data"]?["fields"]?.arrayValue)
    }
}

func fixture(_ name: String) throws -> Data {
    let url = Bundle.module.url(forResource: "Fixtures/\(name)", withExtension: nil)
        ?? Bundle.module.url(forResource: name, withExtension: nil, subdirectory: "Fixtures")
    guard let url else {
        throw NSError(domain: "fixtures", code: 1, userInfo: [NSLocalizedDescriptionKey: "missing fixture \(name)"])
    }
    return try Data(contentsOf: url)
}
