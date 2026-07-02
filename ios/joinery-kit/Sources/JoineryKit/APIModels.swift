import Foundation

/// The session-key pair minted by `auth/login`. The secret is only ever held
/// in memory and the Keychain.
public struct APICredentials: Equatable, Sendable {
    public let publicKey: String
    public let secretKey: String

    public init(publicKey: String, secretKey: String) {
        self.publicKey = publicKey
        self.secretKey = secretKey
    }
}

/// Subscription tier summary from `auth/login` / `auth/session`. `tier` is
/// null for users with no subscription.
public struct TierSummary: Equatable, Sendable {
    public let name: String
    public let tierLevel: Int
    public let features: [String: Bool]

    init?(json: JSONValue?) {
        guard let json, let name = json["name"]?.stringValue else { return nil }
        self.name = name
        self.tierLevel = json["tier_level"]?.intValue ?? 0
        var features: [String: Bool] = [:]
        if let pairs = json["features"]?.objectValue {
            for (key, value) in pairs {
                features[key] = value.boolValue ?? false
            }
        }
        self.features = features
    }
}

/// The "who am I" summary from `auth/login` and `auth/session`.
public struct UserSummary: Equatable, Sendable {
    public let userId: Int
    public let firstName: String
    public let lastName: String
    public let displayName: String
    public let email: String
    public let permission: Int
    public let tier: TierSummary?

    init?(json: JSONValue?) {
        guard let json, let userId = json["user_id"]?.intValue else { return nil }
        self.userId = userId
        self.firstName = json["first_name"]?.stringValue ?? ""
        self.lastName = json["last_name"]?.stringValue ?? ""
        self.displayName = json["display_name"]?.stringValue ?? ""
        self.email = json["email"]?.stringValue ?? ""
        self.permission = json["permission"]?.intValue ?? 0
        self.tier = TierSummary(json: json["tier"])
    }
}

/// Successful `auth/login` payload.
public struct LoginResult: Sendable {
    public let credentials: APICredentials
    public let expiresTime: Date?
    public let user: UserSummary?

    init?(data: JSONValue?) {
        guard let data,
              let publicKey = data["public_key"]?.stringValue,
              let secretKey = data["secret_key"]?.stringValue else { return nil }
        self.credentials = APICredentials(publicKey: publicKey, secretKey: secretKey)
        self.expiresTime = JoineryTimestamp.parse(data["expires_time"]?.stringValue)
        self.user = UserSummary(json: data["user"])
    }
}

/// API timestamps: `YYYY-MM-DD HH:MM:SS`, UTC, no zone suffix
/// (docs/api.md § Timestamps).
public enum JoineryTimestamp {
    private static let formatter: DateFormatter = {
        let f = DateFormatter()
        f.dateFormat = "yyyy-MM-dd HH:mm:ss"
        f.timeZone = TimeZone(identifier: "UTC")
        f.locale = Locale(identifier: "en_US_POSIX")
        return f
    }()

    public static func parse(_ string: String?) -> Date? {
        guard let string, !string.isEmpty else { return nil }
        return formatter.date(from: string)
    }

    public static func format(_ date: Date) -> String {
        formatter.string(from: date)
    }
}
