import Foundation
import JoineryKit

/// State for the subscriptions screen: active + cancelled lists, current
/// tier, and payment source. Cancel goes through `orders_recurring_action`
/// and reloads — the server is the single source of truth.
@MainActor
public final class SubscriptionStore: ObservableObject {
    public enum Phase: Equatable {
        case loading
        case loaded
        case failed(String)
    }

    @Published public private(set) var phase: Phase = .loading
    @Published public private(set) var payload: SubscriptionSummaryPayload?
    @Published public private(set) var cancelError: String?

    public let api: MemberAPI

    public init(api: MemberAPI) {
        self.api = api
    }

    public func initialLoad() async {
        phase = .loading
        await reload()
    }

    public func reload() async {
        do {
            payload = try await api.subscriptions()
            phase = .loaded
        } catch {
            if case .loaded = phase { return }
            phase = .failed((error as? JoineryAPIError)?.displayMessage ?? error.localizedDescription)
        }
    }

    public func cancel(orderItemID: Int) async {
        cancelError = nil
        do {
            try await api.cancelSubscription(orderItemID: orderItemID)
            await reload()
        } catch {
            cancelError = (error as? JoineryAPIError)?.displayMessage ?? "Could not cancel the subscription."
        }
    }

    public func clearCancelError() {
        cancelError = nil
    }
}
