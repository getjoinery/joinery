import Foundation
import JoineryKit

/// State for the dashboard screen: one summary load, refreshed on pull and
/// on return from a child screen (subscription cancel, event withdraw, mute
/// etc. change the counts). The server is the single source of truth,
/// shared with the web dashboard.
@MainActor
public final class ProfileStore: ObservableObject {
    public enum Phase: Equatable {
        case loading
        case loaded
        case failed(String)
    }

    @Published public private(set) var phase: Phase = .loading
    @Published public private(set) var summary: DashboardSummary?

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
            summary = try await api.dashboard()
            phase = .loaded
        } catch {
            if case .loaded = phase { return }
            phase = .failed((error as? JoineryAPIError)?.displayMessage ?? error.localizedDescription)
        }
    }
}
