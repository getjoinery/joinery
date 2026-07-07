import Foundation
import JoineryKit

/// State for the status-tabbed event list. 10/page, matching the web page.
/// Withdraw goes through `event_withdraw` (with a client confirmation
/// alert, matching the web flow) and reloads.
@MainActor
public final class EventListStore: ObservableObject {
    public enum Phase: Equatable {
        case loading
        case loaded
        case failed(String)
    }

    @Published public private(set) var phase: Phase = .loading
    @Published public private(set) var registrations: [EventRegistration] = []
    @Published public private(set) var isLoadingMore = false
    @Published public var status: EventStatusFilter = .all
    @Published public private(set) var withdrawError: String?

    public let api: MemberAPI
    private var totalCount = 0

    public init(api: MemberAPI) {
        self.api = api
    }

    public var hasMore: Bool { registrations.count < totalCount }

    public func initialLoad() async {
        phase = .loading
        await reload()
    }

    public func reload() async {
        do {
            let page = try await api.events(status: status, offset: 0)
            registrations = page.registrations
            totalCount = page.totalCount
            phase = .loaded
        } catch {
            if case .loaded = phase { return }
            phase = .failed((error as? JoineryAPIError)?.displayMessage ?? error.localizedDescription)
        }
    }

    public func loadMore() async {
        guard hasMore, !isLoadingMore else { return }
        isLoadingMore = true
        defer { isLoadingMore = false }
        do {
            let page = try await api.events(status: status, offset: registrations.count)
            let known = Set(registrations.map(\.registrantID))
            registrations += page.registrations.filter { !known.contains($0.registrantID) }
            totalCount = page.totalCount
        } catch {
            // Paging failures are silent; the next scroll retries.
        }
    }

    public func select(status newStatus: EventStatusFilter) async {
        guard newStatus != status else { return }
        status = newStatus
        await initialLoad()
    }

    public func withdraw(registrantID: Int) async {
        withdrawError = nil
        do {
            try await api.withdraw(registrantID: registrantID)
            await reload()
        } catch {
            withdrawError = (error as? JoineryAPIError)?.displayMessage ?? "Could not withdraw from the event."
        }
    }

    public func clearWithdrawError() {
        withdrawError = nil
    }
}
