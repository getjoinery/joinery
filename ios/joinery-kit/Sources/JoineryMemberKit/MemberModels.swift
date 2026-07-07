import Foundation
import JoineryKit

/// The `profile_dashboard` payload. Section keys are gated server-side by
/// `messaging_active` / `products_active` / `subscriptions_active` — an
/// absent key means the screen renders no section for it, not an error.
/// Optional fields therefore carry `nil` rather than a default when the
/// server omitted the key, so the dashboard screen can tell "off" apart
/// from "empty".
public struct DashboardSummary: Equatable, Sendable {
    public let userName: String
    public let userEmail: String
    public let avatarURL: String
    public let address: String

    public let pendingSurveys: [PendingSurvey]
    public let upcomingEvents: [DashboardEvent]
    public let upcomingEventCount: Int

    /// Present only when messaging is active.
    public let unreadConversationCount: Int?
    public let recentConversations: [DashboardConversation]?

    /// Present only when products are active.
    public let recentOrders: [DashboardOrder]?

    /// Present only when products + subscriptions are both active.
    public let recentSubscriptions: [DashboardSubscription]?
    public let activeSubscriptionCount: Int?

    public let mailingLists: [String]

    public var messagingActive: Bool { unreadConversationCount != nil }
    public var productsActive: Bool { recentOrders != nil }
    public var subscriptionsActive: Bool { recentSubscriptions != nil }

    public init?(data: JSONValue?) {
        guard let data else { return nil }
        let user = data["user"]
        userName = user?["name"]?.stringValue ?? ""
        userEmail = user?["email"]?.stringValue ?? ""
        avatarURL = user?["avatar_url"]?.stringValue ?? ""
        address = user?["address"]?.stringValue ?? ""

        pendingSurveys = (data["pending_surveys"]?.arrayValue ?? []).compactMap(PendingSurvey.init(json:))
        upcomingEvents = (data["upcoming_events"]?.arrayValue ?? []).compactMap(DashboardEvent.init(json:))
        upcomingEventCount = data["upcoming_event_count"]?.intValue ?? 0

        unreadConversationCount = data["unread_conversation_count"]?.intValue
        recentConversations = data["recent_conversations"]?.arrayValue.map { $0.compactMap(DashboardConversation.init(json:)) }

        recentOrders = data["recent_orders"]?.arrayValue.map { $0.compactMap(DashboardOrder.init(json:)) }

        recentSubscriptions = data["subscriptions"]?.arrayValue.map { $0.compactMap(DashboardSubscription.init(json:)) }
        activeSubscriptionCount = data["active_subscription_count"]?.intValue

        mailingLists = (data["mailing_lists"]?.arrayValue ?? []).compactMap(\.stringValue)
    }
}

public struct PendingSurvey: Identifiable, Equatable, Sendable {
    public let surveyID: Int
    public let eventID: Int
    public let eventName: String
    public var id: Int { surveyID }

    init?(json: JSONValue) {
        guard let surveyID = json["survey_id"]?.intValue else { return nil }
        self.surveyID = surveyID
        eventID = json["event_id"]?.intValue ?? 0
        eventName = json["event_name"]?.stringValue ?? ""
    }
}

public struct DashboardEvent: Identifiable, Equatable, Sendable {
    public let registrantID: Int
    public let eventID: Int
    public let eventName: String
    public let nextSessionTime: String?
    public let expiresTime: String?
    public let webURL: String
    public var id: Int { registrantID }

    init?(json: JSONValue) {
        guard let registrantID = json["registrant_id"]?.intValue else { return nil }
        self.registrantID = registrantID
        eventID = json["event_id"]?.intValue ?? 0
        eventName = json["event_name"]?.stringValue ?? ""
        nextSessionTime = json["next_session_time"]?.stringValue
        expiresTime = json["expires_time"]?.stringValue
        webURL = json["web_url"]?.stringValue ?? ""
    }
}

public struct DashboardConversation: Identifiable, Equatable, Sendable {
    public let conversationID: Int
    public let otherDisplayName: String
    public let preview: String
    public let lastMessageTime: String?
    public let unread: Bool
    public var id: Int { conversationID }

    init?(json: JSONValue) {
        guard let conversationID = json["conversation_id"]?.intValue else { return nil }
        self.conversationID = conversationID
        otherDisplayName = json["other_display_name"]?.stringValue ?? "Unknown"
        preview = json["preview"]?.stringValue ?? ""
        lastMessageTime = json["last_message_time"]?.stringValue
        unread = json["unread"]?.boolValue ?? false
    }
}

public struct DashboardOrder: Identifiable, Equatable, Sendable {
    public let orderID: Int
    public let total: String
    public let date: String
    public var id: Int { orderID }

    init?(json: JSONValue) {
        guard let orderID = json["order_id"]?.intValue else { return nil }
        self.orderID = orderID
        total = json["total"]?.stringValue ?? "0.00"
        date = json["date"]?.stringValue ?? ""
    }
}

public struct DashboardSubscription: Identifiable, Equatable, Sendable {
    public let orderItemID: Int
    public let productName: String
    public let price: String
    public let status: String
    public var id: Int { orderItemID }

    init?(json: JSONValue) {
        guard let orderItemID = json["order_item_id"]?.intValue else { return nil }
        self.orderItemID = orderItemID
        productName = json["product_name"]?.stringValue ?? ""
        price = json["price"]?.stringValue ?? "0.00"
        status = json["status"]?.stringValue ?? "active"
    }
}

// MARK: - order_list

public struct OrderItemSummary: Equatable, Sendable {
    public let productName: String
    public let price: String

    init(json: JSONValue) {
        productName = json["product_name"]?.stringValue ?? ""
        price = json["price"]?.stringValue ?? "0.00"
    }
}

public struct OrderSummary: Identifiable, Equatable, Sendable {
    public let orderID: Int
    public let number: Int
    public let total: String
    public let date: String
    public let items: [OrderItemSummary]
    public var id: Int { orderID }

    init?(json: JSONValue) {
        guard let orderID = json["order_id"]?.intValue else { return nil }
        self.orderID = orderID
        number = json["number"]?.intValue ?? orderID
        total = json["total"]?.stringValue ?? "0.00"
        date = json["date"]?.stringValue ?? ""
        items = (json["items"]?.arrayValue ?? []).map(OrderItemSummary.init(json:))
    }
}

/// The `order_list` payload. 10/page, matching the web order history page.
public struct OrderPage: Equatable, Sendable {
    public static let perPage = 10

    public let orders: [OrderSummary]
    public let totalCount: Int
    public let offset: Int
    public let perPage: Int

    public init?(data: JSONValue?) {
        guard let data else { return nil }
        orders = (data["orders"]?.arrayValue ?? []).compactMap(OrderSummary.init(json:))
        totalCount = data["total_count"]?.intValue ?? 0
        offset = data["offset"]?.intValue ?? 0
        perPage = data["per_page"]?.intValue ?? Self.perPage
    }
}

// MARK: - subscription_summary

public struct SubscriptionRow: Identifiable, Equatable, Sendable {
    public let orderItemID: Int
    public let productName: String
    public let period: String
    public let price: String
    public let status: String
    public let renewalOrEndDate: String?
    public let canCancel: Bool
    public let paymentSource: String
    public var id: Int { orderItemID }

    init?(json: JSONValue) {
        guard let orderItemID = json["order_item_id"]?.intValue else { return nil }
        self.orderItemID = orderItemID
        productName = json["product_name"]?.stringValue ?? ""
        period = json["period"]?.stringValue ?? ""
        price = json["price"]?.stringValue ?? "0.00"
        status = json["status"]?.stringValue ?? "active"
        renewalOrEndDate = json["renewal_or_end_date"]?.stringValue
        canCancel = json["can_cancel"]?.boolValue ?? false
        paymentSource = json["payment_source"]?.stringValue ?? "none"
    }
}

public struct CurrentTier: Equatable, Sendable {
    public let tierID: Int
    public let name: String

    init?(json: JSONValue?) {
        guard let json, !json.isNull, let tierID = json["tier_id"]?.intValue else { return nil }
        self.tierID = tierID
        name = json["name"]?.stringValue ?? ""
    }
}

public struct SubscriptionSummaryPayload: Equatable, Sendable {
    public let activeSubscriptions: [SubscriptionRow]
    public let cancelledSubscriptions: [SubscriptionRow]
    public let currentTier: CurrentTier?
    /// stripe | paypal | none — which management affordances to show.
    public let paymentSource: String

    public init?(data: JSONValue?) {
        guard let data else { return nil }
        activeSubscriptions = (data["active_subscriptions"]?.arrayValue ?? []).compactMap(SubscriptionRow.init(json:))
        cancelledSubscriptions = (data["cancelled_subscriptions"]?.arrayValue ?? []).compactMap(SubscriptionRow.init(json:))
        currentTier = CurrentTier(json: data["current_tier"])
        paymentSource = data["payment_source"]?.stringValue ?? "none"
    }
}

// MARK: - my_events

/// Status tabs matching the web My Events page.
public enum EventStatusFilter: String, CaseIterable, Identifiable, Sendable {
    case all
    case active
    case expired
    case canceled
    case completed

    public var id: String { rawValue }

    public var title: String {
        switch self {
        case .all: return "All"
        case .active: return "Active"
        case .expired: return "Expired"
        case .canceled: return "Canceled"
        case .completed: return "Completed"
        }
    }
}

public struct EventRegistration: Identifiable, Equatable, Sendable {
    public let registrantID: Int
    public let eventID: Int
    public let eventName: String
    public let sessionDisplayType: Int
    public let nextSessionTime: String?
    public let status: String
    public let expiresTime: String?
    public let webURL: String
    public var id: Int { registrantID }

    init?(json: JSONValue) {
        guard let registrantID = json["registrant_id"]?.intValue else { return nil }
        self.registrantID = registrantID
        eventID = json["event_id"]?.intValue ?? 0
        eventName = json["event_name"]?.stringValue ?? ""
        sessionDisplayType = json["session_display_type"]?.intValue ?? 0
        nextSessionTime = json["next_session_time"]?.stringValue
        status = json["status"]?.stringValue ?? "active"
        expiresTime = json["expires_time"]?.stringValue
        webURL = json["web_url"]?.stringValue ?? ""
    }
}

/// The `my_events` payload. 10/page, matching the web My Events page.
public struct EventPage: Equatable, Sendable {
    public static let perPage = 10

    public let registrations: [EventRegistration]
    public let totalCount: Int
    public let offset: Int
    public let perPage: Int
    public let statusFilter: String

    public init?(data: JSONValue?) {
        guard let data else { return nil }
        registrations = (data["registrations"]?.arrayValue ?? []).compactMap(EventRegistration.init(json:))
        totalCount = data["total_count"]?.intValue ?? 0
        offset = data["offset"]?.intValue ?? 0
        perPage = data["per_page"]?.intValue ?? Self.perPage
        statusFilter = data["status_filter"]?.stringValue ?? "all"
    }
}

// MARK: - Shared display helpers

public enum MemberDisplay {
    private static let dbFormatter: DateFormatter = {
        let f = DateFormatter()
        f.locale = Locale(identifier: "en_US_POSIX")
        f.timeZone = TimeZone(identifier: "UTC")
        f.dateFormat = "yyyy-MM-dd HH:mm:ss"
        return f
    }()

    /// Server times are UTC "yyyy-MM-dd HH:mm:ss(.ffffff)".
    public static func date(_ dbTime: String?) -> Date? {
        guard let dbTime, dbTime.count >= 19 else { return nil }
        return dbFormatter.date(from: String(dbTime.prefix(19)))
    }

    /// "Jul 3, 2026" — a plain calendar date for lists.
    public static func dateLabel(_ dbTime: String?) -> String {
        guard let date = date(dbTime) else { return "" }
        let f = DateFormatter()
        f.dateStyle = .medium
        f.timeStyle = .none
        return f.string(from: date)
    }

    /// "Jul 3, 2026 at 9:41 AM" — date + time for messages and sessions.
    public static func dateTimeLabel(_ dbTime: String?) -> String {
        guard let date = date(dbTime) else { return "" }
        let f = DateFormatter()
        f.dateStyle = .medium
        f.timeStyle = .short
        return f.string(from: date)
    }

    /// Gmail-style short stamp for conversation rows: time today, "Jul 3"
    /// this year, else a short date.
    public static func listStamp(_ dbTime: String?, now: Date = Date()) -> String {
        guard let date = date(dbTime) else { return "" }
        let cal = Calendar.current
        let f = DateFormatter()
        f.locale = Locale.current
        if cal.isDate(date, inSameDayAs: now) {
            f.timeStyle = .short
            f.dateStyle = .none
        } else if cal.component(.year, from: date) == cal.component(.year, from: now) {
            f.setLocalizedDateFormatFromTemplate("MMM d")
        } else {
            f.dateStyle = .short
            f.timeStyle = .none
        }
        return f.string(from: date)
    }
}
