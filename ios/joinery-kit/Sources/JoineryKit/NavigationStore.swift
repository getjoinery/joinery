import Foundation

/// Fetches and holds the navigation table for the signed-in user. Owned by
/// the navigation shell; refreshed on foreground so server-side menu changes
/// (and revoked sessions — the fetch 401s) surface without a relaunch.
@MainActor
public final class NavigationStore: ObservableObject {
    public enum Phase: Equatable {
        case loading
        case loaded(AppNavigation)
        case failed(message: String)
    }

    @Published public private(set) var phase: Phase = .loading

    private let client: APIClient

    public init(client: APIClient) {
        self.client = client
    }

    /// Initial fetch: flips to `.loading` first so the shell shows a spinner.
    public func load() async {
        phase = .loading
        await fetch()
    }

    /// Background refresh: keeps the current table on screen; only a
    /// successful fetch replaces it.
    public func refresh() async {
        await fetch(keepCurrentOnFailure: true)
    }

    private func fetch(keepCurrentOnFailure: Bool = false) async {
        do {
            let envelope = try await client.request("GET", "/api/v1/app/navigation")
            if let navigation = AppNavigation(data: envelope["data"]) {
                phase = .loaded(navigation)
            } else if !keepCurrentOnFailure {
                phase = .failed(message: "Navigation could not be loaded.")
            }
        } catch let error as JoineryAPIError {
            // authentication/upgrade flip the app state via the client
            // handlers; the shell unmounts, so the phase here is moot.
            if !keepCurrentOnFailure {
                phase = .failed(message: error.displayMessage)
            }
        } catch {
            if !keepCurrentOnFailure {
                phase = .failed(message: "Navigation could not be loaded.")
            }
        }
    }
}
