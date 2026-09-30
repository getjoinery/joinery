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
    /// End-to-end mail on this phone (specs/fortress_mobile_apps.md): opens
    /// Fortress rows, holds the mail key, runs the device-side work.
    public let fortress: FortressSession?
    /// This phone's own index over Fortress mail (§ R5).
    public let deviceSearch: DeviceSearch?
    /// What the phone's search said about itself on the last search.
    @Published public private(set) var searchNote: String?
    private var page = 1
    /// Ignores stale in-flight loads after the view/mailbox/search changes.
    private var loadGeneration = 0
    /// device_hits for the active query, computed once per search.
    private var hitsForQuery: (query: String, packed: String?, deviceOnly: Bool)?

    public init(api: MailAPI, fortress: FortressSession? = nil, deviceSearch: DeviceSearch? = nil) {
        self.api = api
        self.fortress = fortress
        self.deviceSearch = deviceSearch
    }

    /// Some mailbox in view is end-to-end.
    public var fortressInView: Bool {
        if let box = selectedMailbox { return box.isFortress }
        return home?.mailboxes.contains { $0.isFortress } ?? false
    }

    /// Every mailbox in view is end-to-end: a search sends no term to the server.
    public var fortressOnlyInView: Bool {
        if let box = selectedMailbox { return box.isFortress }
        guard let boxes = home?.mailboxes, !boxes.isEmpty else { return false }
        return boxes.allSatisfy(\.isFortress)
    }

    /// Fill Fortress rows from the key this phone holds; placeholders otherwise.
    private func opened(_ page: ThreadPage) -> ThreadPage {
        guard let fortress, page.threads.contains(where: { $0.sealed != nil }) else { return page }
        return fortress.openList(page)
    }

    /// Re-open the loaded rows in place (after an unlock or a lock).
    public func reopenRows() async {
        await reload(refreshMailboxes: true)
    }

    /// The page for the current slice, a search over end-to-end mail adding
    /// this phone's hits (device_hits), or sending no term at all (device_only).
    private func fetch(page number: Int) async throws -> ThreadPage {
        guard !activeQuery.isEmpty, view != .drafts, fortressInView, let deviceSearch, let fortress else {
            return opened(try await api.threadList(aliasID: selectedAlias, view: view, query: activeQuery, page: number))
        }
        if hitsForQuery?.query != activeQuery {
            var packed: String? = nil
            if fortress.isOpen {
                let r = await deviceSearch.hits(activeQuery)
                packed = r.packed
                searchNote = r.note
            } else {
                searchNote = "Unlock your mail to search end-to-end encrypted messages."
            }
            hitsForQuery = (activeQuery, packed, fortressOnlyInView)
        }
        let h = hitsForQuery!
        return opened(try await api.threadList(aliasID: selectedAlias, view: view, query: activeQuery, page: number,
                                               deviceHits: h.packed, deviceOnly: h.deviceOnly))
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
            home = try await api.mailboxes()
            apply(try await fetch(page: 1), reset: true)
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
            let firstPage = try await fetch(page: 1)
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
            let next = try await fetch(page: page + 1)
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
        hitsForQuery = nil
        await reload()
    }

    public func clearSearch() async {
        guard !activeQuery.isEmpty else { return }
        searchText = ""
        activeQuery = ""
        hitsForQuery = nil
        searchNote = nil
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
