package com.getjoinery.memberkit

import com.getjoinery.android.JsonValue
import java.text.DateFormat
import java.text.SimpleDateFormat
import java.util.Calendar
import java.util.Date
import java.util.Locale
import java.util.TimeZone

/**
 * The `profile_dashboard` payload. Section keys are gated server-side by
 * `messaging_active` / `products_active` / `subscriptions_active` — an absent
 * key means the screen renders no section for it, not an error. Nullable
 * fields therefore carry `null` (not a default) when the server omitted the
 * key, so the dashboard can tell "off" apart from "empty".
 */
data class DashboardSummary(
    val userName: String,
    val userEmail: String,
    val avatarUrl: String,
    val address: String,
    val pendingSurveys: List<PendingSurvey>,
    val upcomingEvents: List<DashboardEvent>,
    val upcomingEventCount: Int,
    /** Present only when messaging is active. */
    val unreadConversationCount: Int?,
    val recentConversations: List<DashboardConversation>?,
    /** Present only when products are active. */
    val recentOrders: List<DashboardOrder>?,
    /** Present only when products + subscriptions are both active. */
    val recentSubscriptions: List<DashboardSubscription>?,
    val activeSubscriptionCount: Int?,
    val mailingLists: List<String>,
) {
    val messagingActive: Boolean get() = unreadConversationCount != null
    val productsActive: Boolean get() = recentOrders != null
    val subscriptionsActive: Boolean get() = recentSubscriptions != null

    companion object {
        fun from(data: JsonValue?): DashboardSummary? {
            if (data == null) return null
            val user = data["user"]
            return DashboardSummary(
                userName = user?.get("name")?.stringValue ?: "",
                userEmail = user?.get("email")?.stringValue ?: "",
                avatarUrl = user?.get("avatar_url")?.stringValue ?: "",
                address = user?.get("address")?.stringValue ?: "",
                pendingSurveys = (data["pending_surveys"]?.arrayValue ?: emptyList())
                    .mapNotNull { PendingSurvey.from(it) },
                upcomingEvents = (data["upcoming_events"]?.arrayValue ?: emptyList())
                    .mapNotNull { DashboardEvent.from(it) },
                upcomingEventCount = data["upcoming_event_count"]?.intValue ?: 0,
                unreadConversationCount = data["unread_conversation_count"]?.takeUnless { it.isNull }?.intValue,
                recentConversations = data["recent_conversations"]?.arrayValue
                    ?.mapNotNull { DashboardConversation.from(it) },
                recentOrders = data["recent_orders"]?.arrayValue
                    ?.mapNotNull { DashboardOrder.from(it) },
                recentSubscriptions = data["subscriptions"]?.arrayValue
                    ?.mapNotNull { DashboardSubscription.from(it) },
                activeSubscriptionCount = data["active_subscription_count"]?.takeUnless { it.isNull }?.intValue,
                mailingLists = (data["mailing_lists"]?.arrayValue ?: emptyList())
                    .mapNotNull { it.stringValue },
            )
        }
    }
}

data class PendingSurvey(
    val surveyId: Int,
    val eventId: Int,
    val eventName: String,
) {
    companion object {
        fun from(json: JsonValue): PendingSurvey? {
            val surveyId = json["survey_id"]?.intValue ?: return null
            return PendingSurvey(
                surveyId = surveyId,
                eventId = json["event_id"]?.intValue ?: 0,
                eventName = json["event_name"]?.stringValue ?: "",
            )
        }
    }
}

data class DashboardEvent(
    val registrantId: Int,
    val eventId: Int,
    val eventName: String,
    val nextSessionTime: String?,
    val expiresTime: String?,
    val webUrl: String,
) {
    companion object {
        fun from(json: JsonValue): DashboardEvent? {
            val registrantId = json["registrant_id"]?.intValue ?: return null
            return DashboardEvent(
                registrantId = registrantId,
                eventId = json["event_id"]?.intValue ?: 0,
                eventName = json["event_name"]?.stringValue ?: "",
                nextSessionTime = json["next_session_time"]?.takeUnless { it.isNull }?.stringValue,
                expiresTime = json["expires_time"]?.takeUnless { it.isNull }?.stringValue,
                webUrl = json["web_url"]?.stringValue ?: "",
            )
        }
    }
}

data class DashboardConversation(
    val conversationId: Int,
    val otherDisplayName: String,
    val preview: String,
    val lastMessageTime: String?,
    val unread: Boolean,
) {
    companion object {
        fun from(json: JsonValue): DashboardConversation? {
            val conversationId = json["conversation_id"]?.intValue ?: return null
            return DashboardConversation(
                conversationId = conversationId,
                otherDisplayName = json["other_display_name"]?.stringValue ?: "Unknown",
                preview = json["preview"]?.stringValue ?: "",
                lastMessageTime = json["last_message_time"]?.takeUnless { it.isNull }?.stringValue,
                unread = json["unread"]?.boolValue ?: false,
            )
        }
    }
}

data class DashboardOrder(
    val orderId: Int,
    val total: String,
    val date: String,
) {
    companion object {
        fun from(json: JsonValue): DashboardOrder? {
            val orderId = json["order_id"]?.intValue ?: return null
            return DashboardOrder(
                orderId = orderId,
                total = json["total"]?.stringValue ?: "0.00",
                date = json["date"]?.stringValue ?: "",
            )
        }
    }
}

data class DashboardSubscription(
    val orderItemId: Int,
    val productName: String,
    val price: String,
    val status: String,
) {
    companion object {
        fun from(json: JsonValue): DashboardSubscription? {
            val orderItemId = json["order_item_id"]?.intValue ?: return null
            return DashboardSubscription(
                orderItemId = orderItemId,
                productName = json["product_name"]?.stringValue ?: "",
                price = json["price"]?.stringValue ?: "0.00",
                status = json["status"]?.stringValue ?: "active",
            )
        }
    }
}

// MARK: - order_list

data class OrderItemSummary(
    val productName: String,
    val price: String,
) {
    companion object {
        fun from(json: JsonValue): OrderItemSummary = OrderItemSummary(
            productName = json["product_name"]?.stringValue ?: "",
            price = json["price"]?.stringValue ?: "0.00",
        )
    }
}

data class OrderSummary(
    val orderId: Int,
    val number: Int,
    val total: String,
    val date: String,
    val items: List<OrderItemSummary>,
) {
    companion object {
        fun from(json: JsonValue): OrderSummary? {
            val orderId = json["order_id"]?.intValue ?: return null
            return OrderSummary(
                orderId = orderId,
                number = json["number"]?.intValue ?: orderId,
                total = json["total"]?.stringValue ?: "0.00",
                date = json["date"]?.stringValue ?: "",
                items = (json["items"]?.arrayValue ?: emptyList()).map { OrderItemSummary.from(it) },
            )
        }
    }
}

/** The `order_list` payload. 10/page, matching the web order history page. */
data class OrderPage(
    val orders: List<OrderSummary>,
    val totalCount: Int,
    val offset: Int,
    val perPage: Int,
) {
    companion object {
        const val PER_PAGE = 10

        fun from(data: JsonValue?): OrderPage? {
            if (data == null) return null
            return OrderPage(
                orders = (data["orders"]?.arrayValue ?: emptyList()).mapNotNull { OrderSummary.from(it) },
                totalCount = data["total_count"]?.intValue ?: 0,
                offset = data["offset"]?.intValue ?: 0,
                perPage = data["per_page"]?.intValue ?: PER_PAGE,
            )
        }
    }
}

// MARK: - subscription_summary

data class SubscriptionRow(
    val orderItemId: Int,
    val productName: String,
    val period: String,
    val price: String,
    val status: String,
    val renewalOrEndDate: String?,
    val canCancel: Boolean,
    val paymentSource: String,
) {
    companion object {
        fun from(json: JsonValue): SubscriptionRow? {
            val orderItemId = json["order_item_id"]?.intValue ?: return null
            return SubscriptionRow(
                orderItemId = orderItemId,
                productName = json["product_name"]?.stringValue ?: "",
                period = json["period"]?.stringValue ?: "",
                price = json["price"]?.stringValue ?: "0.00",
                status = json["status"]?.stringValue ?: "active",
                renewalOrEndDate = json["renewal_or_end_date"]?.takeUnless { it.isNull }?.stringValue,
                canCancel = json["can_cancel"]?.boolValue ?: false,
                paymentSource = json["payment_source"]?.stringValue ?: "none",
            )
        }
    }
}

data class CurrentTier(
    val tierId: Int,
    val name: String,
) {
    companion object {
        fun from(json: JsonValue?): CurrentTier? {
            if (json == null || json.isNull) return null
            val tierId = json["tier_id"]?.intValue ?: return null
            return CurrentTier(tierId = tierId, name = json["name"]?.stringValue ?: "")
        }
    }
}

data class SubscriptionSummaryPayload(
    val activeSubscriptions: List<SubscriptionRow>,
    val cancelledSubscriptions: List<SubscriptionRow>,
    val currentTier: CurrentTier?,
    /** stripe | paypal | none — which management affordances to show. */
    val paymentSource: String,
) {
    companion object {
        fun from(data: JsonValue?): SubscriptionSummaryPayload? {
            if (data == null) return null
            return SubscriptionSummaryPayload(
                activeSubscriptions = (data["active_subscriptions"]?.arrayValue ?: emptyList())
                    .mapNotNull { SubscriptionRow.from(it) },
                cancelledSubscriptions = (data["cancelled_subscriptions"]?.arrayValue ?: emptyList())
                    .mapNotNull { SubscriptionRow.from(it) },
                currentTier = CurrentTier.from(data["current_tier"]),
                paymentSource = data["payment_source"]?.stringValue ?: "none",
            )
        }
    }
}

// MARK: - my_events

/** Status tabs matching the web My Events page. */
enum class EventStatusFilter(val slug: String, val title: String) {
    ALL("all", "All"),
    ACTIVE("active", "Active"),
    EXPIRED("expired", "Expired"),
    CANCELED("canceled", "Canceled"),
    COMPLETED("completed", "Completed"),
}

data class EventRegistration(
    val registrantId: Int,
    val eventId: Int,
    val eventName: String,
    val sessionDisplayType: Int,
    val nextSessionTime: String?,
    val status: String,
    val expiresTime: String?,
    val webUrl: String,
) {
    companion object {
        fun from(json: JsonValue): EventRegistration? {
            val registrantId = json["registrant_id"]?.intValue ?: return null
            return EventRegistration(
                registrantId = registrantId,
                eventId = json["event_id"]?.intValue ?: 0,
                eventName = json["event_name"]?.stringValue ?: "",
                sessionDisplayType = json["session_display_type"]?.intValue ?: 0,
                nextSessionTime = json["next_session_time"]?.takeUnless { it.isNull }?.stringValue,
                status = json["status"]?.stringValue ?: "active",
                expiresTime = json["expires_time"]?.takeUnless { it.isNull }?.stringValue,
                webUrl = json["web_url"]?.stringValue ?: "",
            )
        }
    }
}

/** The `my_events` payload. 10/page, matching the web My Events page. */
data class EventPage(
    val registrations: List<EventRegistration>,
    val totalCount: Int,
    val offset: Int,
    val perPage: Int,
    val statusFilter: String,
) {
    companion object {
        const val PER_PAGE = 10

        fun from(data: JsonValue?): EventPage? {
            if (data == null) return null
            return EventPage(
                registrations = (data["registrations"]?.arrayValue ?: emptyList())
                    .mapNotNull { EventRegistration.from(it) },
                totalCount = data["total_count"]?.intValue ?: 0,
                offset = data["offset"]?.intValue ?: 0,
                perPage = data["per_page"]?.intValue ?: PER_PAGE,
                statusFilter = data["status_filter"]?.stringValue ?: "all",
            )
        }
    }
}

// MARK: - Shared display helpers

object MemberDisplay {
    private fun dbFormatter(): SimpleDateFormat =
        SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.US).apply {
            timeZone = TimeZone.getTimeZone("UTC")
            isLenient = false
        }

    /** Server times are UTC "yyyy-MM-dd HH:mm:ss(.ffffff)". */
    fun date(dbTime: String?): Date? {
        if (dbTime == null || dbTime.length < 19) return null
        return try {
            dbFormatter().parse(dbTime.substring(0, 19))
        } catch (e: Exception) {
            null
        }
    }

    /** "Jul 3, 2026" — a plain calendar date for lists. */
    fun dateLabel(dbTime: String?): String {
        val date = date(dbTime) ?: return ""
        return DateFormat.getDateInstance(DateFormat.MEDIUM).format(date)
    }

    /** "Jul 3, 2026, 9:41 AM" — date + time for messages and sessions. */
    fun dateTimeLabel(dbTime: String?): String {
        val date = date(dbTime) ?: return ""
        return DateFormat.getDateTimeInstance(DateFormat.MEDIUM, DateFormat.SHORT).format(date)
    }

    /** Gmail-style short stamp for conversation rows: time today, "Jul 3" this
     *  year, else a short date. */
    fun listStamp(dbTime: String?, now: Date = Date()): String {
        val date = date(dbTime) ?: return ""
        val dateCal = Calendar.getInstance().apply { time = date }
        val nowCal = Calendar.getInstance().apply { time = now }
        val sameDay = dateCal.get(Calendar.YEAR) == nowCal.get(Calendar.YEAR) &&
            dateCal.get(Calendar.DAY_OF_YEAR) == nowCal.get(Calendar.DAY_OF_YEAR)
        return when {
            sameDay -> DateFormat.getTimeInstance(DateFormat.SHORT).format(date)
            dateCal.get(Calendar.YEAR) == nowCal.get(Calendar.YEAR) ->
                SimpleDateFormat("MMM d", Locale.getDefault()).format(date)
            else -> DateFormat.getDateInstance(DateFormat.SHORT).format(date)
        }
    }
}
