import Foundation
import JoineryKit

/// State for the paginated order list. 10/page, matching the web page —
/// `OrderPage.perPage` is the client-side fallback but the server's
/// `per_page` always wins.
@MainActor
public final class OrderListStore: ObservableObject {
    public enum Phase: Equatable {
        case loading
        case loaded
        case failed(String)
    }

    @Published public private(set) var phase: Phase = .loading
    @Published public private(set) var orders: [OrderSummary] = []
    @Published public private(set) var isLoadingMore = false

    public let api: MemberAPI
    private var totalCount = 0

    public init(api: MemberAPI) {
        self.api = api
    }

    public var hasMore: Bool { orders.count < totalCount }

    public func initialLoad() async {
        phase = .loading
        await reload()
    }

    public func reload() async {
        do {
            let page = try await api.orders(offset: 0)
            orders = page.orders
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
            let page = try await api.orders(offset: orders.count)
            let known = Set(orders.map(\.orderID))
            orders += page.orders.filter { !known.contains($0.orderID) }
            totalCount = page.totalCount
        } catch {
            // Paging failures are silent; the next scroll retries.
        }
    }
}
