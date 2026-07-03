import Foundation
import JoineryKit

/// State for the mailbox screen: the granted mailboxes plus the thread list
/// for the current mailbox / view / search, with paging. All mutations go
/// through MailAPI and re-read or locally patch the list — the server is the
/// single source of truth shared with the web reader.
@MainActor
public final class MailboxStore: ObservableObject {
    public enum Phase {
        case loading
        case loaded
        case failed(String)
    }

    @Published public private(set) var phase: Phase = .loading
    @Published public private(set) var home: MailboxHome?
    @Published public private(set) var threads: [ThreadSummary] = []
    @Published public private(set) var hasMore = false
    @Published public private(set) var isLoadingMore = false

    @Published public var searchText = ""
    @Published public private(set) var activeQuery = ""
    @Published public private(set) var view: MailView = .inbox
    @Published public private(set) var selectedAlias: Int?

    public let api: MailAPI
    private var page = 1
    /// Ignores stale in-flight loads after the view/mailbox/search changes.
    private var loadGeneration = 0

    public init(api: MailAPI) {
        self.api = api
    }

    /// The mailbox the list is scoped to, when a specific one is selected.
    public var selectedMailbox: Mailbox? {
        guard let selectedAlias else { return nil }
        return home?.mailboxes.first { $0.aliasID == selectedAlias }
    }

    public var title: String {
        if activeQuery.isEmpty == false { return "Search" }
        return view.title
    }

    /// First load: mailboxes and the initial thread page together.
    public func initialLoad() async {
        phase = .loading
        do {
            async let homeTask = api.mailboxes()
            async let pageTask = api.threadList(aliasID: selectedAlias, view: view, query: activeQuery, page: 1)
            let (loadedHome, firstPage) = try await (homeTask, pageTask)
            home = loadedHome
            apply(firstPage, reset: true)
            phase = .loaded
        } catch {
            phase = .failed((error as? JoineryAPIError)?.displayMessage ?? error.localizedDescription)
        }
    }

    /// Re-read the current slice from page 1 (pull-to-refresh, after actions,
    /// after a view/mailbox/search change). Keeps showing the last-good list
    /// while it runs; failures surface only when nothing is loaded yet.
    public func reload(refreshMailboxes: Bool = false) async {
        loadGeneration += 1
        let generation = loadGeneration
        do {
            if refreshMailboxes {
                home = try await api.mailboxes()
            }
            let firstPage = try await api.threadList(aliasID: selectedAlias, view: view, query: activeQuery, page: 1)
            guard generation == loadGeneration else { return }
            apply(firstPage, reset: true)
            phase = .loaded
        } catch {
            guard generation == loadGeneration else { return }
            if case .loaded = phase { return }
            phase = .failed((error as? JoineryAPIError)?.displayMessage ?? error.localizedDescription)
        }
    }

    public func loadMore() async {
        guard hasMore, !isLoadingMore else { return }
        isLoadingMore = true
        defer { isLoadingMore = false }
        let generation = loadGeneration
        do {
            let next = try await api.threadList(aliasID: selectedAlias, view: view, query: activeQuery, page: page + 1)
            guard generation == loadGeneration else { return }
            apply(next, reset: false)
        } catch {
            // Paging failures are silent; the next scroll retries.
        }
    }

    private func apply(_ pageData: ThreadPage, reset: Bool) {
        if reset {
            threads = pageData.threads
        } else {
            let known = Set(threads.map(\.threadKey))
            threads += pageData.threads.filter { !known.contains($0.threadKey) }
        }
        page = pageData.page
        hasMore = pageData.hasMore
    }

    // MARK: Slice changes

    public func select(view newView: MailView) async {
        guard newView != view else { return }
        view = newView
        await reload()
    }

    public func select(alias: Int?) async {
        guard alias != selectedAlias else { return }
        selectedAlias = alias
        await reload()
    }

    public func submitSearch() async {
        activeQuery = searchText.trimmingCharacters(in: .whitespaces)
        await reload()
    }

    public func clearSearch() async {
        guard !activeQuery.isEmpty else { return }
        searchText = ""
        activeQuery = ""
        await reload()
    }

    // MARK: Row actions (list swipes; detail actions reload on return)

    /// Run a thread action and patch the row locally so the list responds
    /// instantly; a background reload then reconciles with the server.
    public func perform(_ action: String, on thread: ThreadSummary) async {
        do {
            try await api.threadAction(action, threadKey: thread.threadKey, aliasID: selectedAlias)
        } catch {
            await reload()
            return
        }
        switch action {
        case "mark_read":
            patch(thread.threadKey) { $0.unreadCount = 0 }
        case "mark_unread":
            patch(thread.threadKey) { $0.unreadCount = max(1, $0.unreadCount) }
        case "star":
            patch(thread.threadKey) { $0.isStarred = true }
        case "unstar":
            patch(thread.threadKey) { $0.isStarred = false }
        case "archive":
            if case .inbox = view { remove(thread.threadKey) } else {
                patch(thread.threadKey) { $0.isArchived = true }
            }
        case "unarchive":
            patch(thread.threadKey) { $0.isArchived = false }
        case "delete", "mark_spam", "mark_not_spam":
            remove(thread.threadKey)
        default:
            await reload()
        }
    }

    /// Local patch used by the detail screen when it changes thread state.
    public func patch(_ threadKey: String, mutate: (inout ThreadSummary) -> Void) {
        guard let index = threads.firstIndex(where: { $0.threadKey == threadKey }) else { return }
        mutate(&threads[index])
    }

    public func remove(_ threadKey: String) {
        threads.removeAll { $0.threadKey == threadKey }
    }
}
