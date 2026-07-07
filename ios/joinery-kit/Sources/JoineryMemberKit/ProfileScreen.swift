import SwiftUI
import JoineryKit

/// Module entry point: call once at app launch to make the member-surface
/// native screens available. The server flips each menu entry to
/// `{type: "native", screen: "..."}`; builds without this module keep
/// loading the matching web page via the entry's fallback URL.
public enum JoineryMember {
    public static func registerScreens() {
        NativeScreenRegistry.register("profile") { context in
            AnyView(ProfileScreen(session: context.session, web: context.web))
        }
        NativeScreenRegistry.register("orders") { context in
            AnyView(OrdersScreen(client: context.session.client))
        }
        NativeScreenRegistry.register("subscriptions") { context in
            AnyView(SubscriptionsScreen(client: context.session.client, web: context.web))
        }
        NativeScreenRegistry.register("events") { context in
            AnyView(EventsScreen(client: context.session.client, web: context.web))
        }
        NativeScreenRegistry.register("conversations") { context in
            AnyView(ConversationsScreen(client: context.session.client))
        }
        NativeScreenRegistry.register("security") { context in
            AnyView(SecurityScreen(session: context.session, web: context.web))
        }
    }
}

/// The member dashboard: user card, an alert row for anything needing
/// attention, stat tiles, and recent-item lists. Every section renders only
/// from keys the server actually sent — a settings-gated section (messaging,
/// products, subscriptions off) is simply absent, not an empty placeholder.
public struct ProfileScreen: View {
    @StateObject private var store: ProfileStore
    private let session: SessionController
    private let web: WebSessionCoordinator?
    private var client: APIClient { session.client }

    public init(session: SessionController, web: WebSessionCoordinator?) {
        self.session = session
        self.web = web
        _store = StateObject(wrappedValue: ProfileStore(api: MemberAPI(client: session.client)))
    }

    public var body: some View {
        content
            .navigationTitle("Profile")
            .navigationBarTitleDisplayMode(.large)
            .task {
                if case .loading = store.phase { await store.initialLoad() }
            }
    }

    @ViewBuilder
    private var content: some View {
        switch store.phase {
        case .loading:
            ProgressView()
                .frame(maxWidth: .infinity, maxHeight: .infinity)
                .accessibilityIdentifier("profile_loading")
        case .failed(let message):
            VStack(spacing: 12) {
                Text(message)
                    .multilineTextAlignment(.center)
                    .foregroundStyle(.secondary)
                    .accessibilityIdentifier("profile_error")
                Button("Try Again") {
                    Task { await store.initialLoad() }
                }
                .buttonStyle(.borderedProminent)
                .accessibilityIdentifier("profile_retry")
            }
            .padding()
        case .loaded:
            if let summary = store.summary {
                dashboard(summary)
            }
        }
    }

    private func dashboard(_ summary: DashboardSummary) -> some View {
        List {
            userCard(summary)
            alertsSection(summary)
            statTiles(summary)
            if !summary.upcomingEvents.isEmpty {
                upcomingEventsSection(summary.upcomingEvents)
            }
            if let conversations = summary.recentConversations, !conversations.isEmpty {
                recentConversationsSection(conversations)
            }
            if let orders = summary.recentOrders, !orders.isEmpty {
                recentOrdersSection(orders)
            }
            if let subs = summary.recentSubscriptions, !subs.isEmpty {
                recentSubscriptionsSection(subs)
            }
            if !summary.mailingLists.isEmpty {
                mailingListsSection(summary.mailingLists)
            }
            moreSection
        }
        .listStyle(.insetGrouped)
        .accessibilityIdentifier("profile_dashboard")
        .refreshable { await store.reload() }
    }

    // MARK: Sections

    private func userCard(_ summary: DashboardSummary) -> some View {
        Section {
            HStack(spacing: 14) {
                AsyncImage(url: URL(string: summary.avatarURL, relativeTo: client.config.baseURL)) { phase in
                    if case .success(let image) = phase {
                        image.resizable().scaledToFill()
                    } else {
                        Circle().fill(Color(.systemGray4))
                    }
                }
                .frame(width: 56, height: 56)
                .clipShape(Circle())
                VStack(alignment: .leading, spacing: 3) {
                    Text(summary.userName.isEmpty ? summary.userEmail : summary.userName)
                        .font(.headline)
                        .accessibilityIdentifier("profile_user_name")
                    Text(summary.userEmail)
                        .font(.subheadline)
                        .foregroundStyle(.secondary)
                    if !summary.address.isEmpty {
                        Text(summary.address)
                            .font(.caption)
                            .foregroundStyle(.secondary)
                    }
                }
            }
            .padding(.vertical, 4)
        }
    }

    @ViewBuilder
    private func alertsSection(_ summary: DashboardSummary) -> some View {
        let unread = summary.unreadConversationCount ?? 0
        if !summary.pendingSurveys.isEmpty || unread > 0 {
            Section {
                if unread > 0 {
                    conversationsLink {
                        Label("\(unread) unread message\(unread == 1 ? "" : "s")", systemImage: "envelope.badge")
                    }
                    .accessibilityIdentifier("profile_alert_unread")
                }
                ForEach(summary.pendingSurveys) { survey in
                    if let web {
                        NavigationLink {
                            WebScreen(title: "Survey", target: "/survey?survey_id=\(survey.surveyID)&event_id=\(survey.eventID)",
                                      client: client, web: web)
                        } label: {
                            Label("Survey pending: \(survey.eventName)", systemImage: "list.bullet.clipboard")
                        }
                    } else {
                        Label("Survey pending: \(survey.eventName)", systemImage: "list.bullet.clipboard")
                    }
                }
            } header: {
                Text("Needs Attention")
            }
        }
    }

    private func statTiles(_ summary: DashboardSummary) -> some View {
        Section {
            eventsLink {
                LabeledContent("Upcoming Events", value: "\(summary.upcomingEventCount)")
            }
            .accessibilityIdentifier("profile_tile_events")
            if summary.subscriptionsActive {
                subscriptionsLink {
                    LabeledContent("Active Subscriptions", value: "\(summary.activeSubscriptionCount ?? 0)")
                }
                .accessibilityIdentifier("profile_tile_subscriptions")
            }
            if summary.productsActive {
                ordersLink {
                    Text("Orders")
                }
                .accessibilityIdentifier("profile_tile_orders")
            }
            if summary.messagingActive {
                conversationsLink {
                    Text("Messages")
                }
                .accessibilityIdentifier("profile_tile_conversations")
            }
            securityLink {
                Text("Security")
            }
            .accessibilityIdentifier("profile_tile_security")
        }
    }

    private func upcomingEventsSection(_ events: [DashboardEvent]) -> some View {
        Section {
            ForEach(events) { event in
                if let web {
                    NavigationLink {
                        WebScreen(title: event.eventName, target: event.webURL, client: client, web: web)
                    } label: {
                        VStack(alignment: .leading, spacing: 2) {
                            Text(event.eventName)
                            if let next = event.nextSessionTime {
                                Text(MemberDisplay.dateTimeLabel(next))
                                    .font(.caption)
                                    .foregroundStyle(.secondary)
                            }
                        }
                    }
                } else {
                    Text(event.eventName)
                }
            }
            eventsLink { Text("See all events") }
        } header: {
            Text("Upcoming Events")
        }
    }

    private func recentConversationsSection(_ conversations: [DashboardConversation]) -> some View {
        Section {
            ForEach(conversations) { conversation in
                NavigationLink {
                    ConversationThreadView(
                        client: client,
                        origin: .conversation(id: conversation.conversationID, otherDisplayName: conversation.otherDisplayName)
                    )
                } label: {
                    VStack(alignment: .leading, spacing: 2) {
                        Text(conversation.otherDisplayName)
                            .fontWeight(conversation.unread ? .semibold : .regular)
                        Text(conversation.preview)
                            .font(.caption)
                            .foregroundStyle(.secondary)
                            .lineLimit(1)
                    }
                }
            }
            conversationsLink { Text("See all messages") }
        } header: {
            Text("Recent Conversations")
        }
    }

    private func recentOrdersSection(_ orders: [DashboardOrder]) -> some View {
        Section {
            ForEach(orders) { order in
                LabeledContent(MemberDisplay.dateLabel(order.date), value: "$\(order.total)")
            }
            ordersLink { Text("See all orders") }
        } header: {
            Text("Recent Orders")
        }
    }

    private func recentSubscriptionsSection(_ subs: [DashboardSubscription]) -> some View {
        Section {
            ForEach(subs) { sub in
                LabeledContent(sub.productName, value: sub.status.capitalized)
            }
            subscriptionsLink { Text("See all subscriptions") }
        } header: {
            Text("Subscriptions")
        }
    }

    private func mailingListsSection(_ lists: [String]) -> some View {
        Section {
            ForEach(lists, id: \.self) { name in
                Text(name)
            }
        } header: {
            Text("Mailing Lists")
        }
    }

    private var moreSection: some View {
        Section {
            if let web {
                NavigationLink {
                    WebScreen(title: "Notifications", target: "/notifications", client: client, web: web)
                } label: {
                    Text("Notifications")
                }
                .accessibilityIdentifier("profile_notifications")
            }
        }
    }

    // MARK: Navigation helpers

    private func ordersLink<Label: View>(@ViewBuilder label: () -> Label) -> some View {
        NavigationLink {
            OrdersScreen(client: client)
        } label: { label() }
    }

    private func subscriptionsLink<Label: View>(@ViewBuilder label: () -> Label) -> some View {
        NavigationLink {
            SubscriptionsScreen(client: client, web: web)
        } label: { label() }
    }

    private func eventsLink<Label: View>(@ViewBuilder label: () -> Label) -> some View {
        NavigationLink {
            EventsScreen(client: client, web: web)
        } label: { label() }
    }

    private func conversationsLink<Label: View>(@ViewBuilder label: () -> Label) -> some View {
        NavigationLink {
            ConversationsScreen(client: client)
        } label: { label() }
    }

    private func securityLink<Label: View>(@ViewBuilder label: () -> Label) -> some View {
        NavigationLink {
            SecurityScreen(session: session, web: web)
        } label: { label() }
    }
}
