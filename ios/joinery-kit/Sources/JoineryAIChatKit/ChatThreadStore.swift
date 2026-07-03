import Foundation
import JoineryKit

/// State for one conversation: its turns, the composer, and the poll loop that
/// delivers a running turn's streamed answer. All writes go through ChatAPI and
/// reconcile against the server; a brand-new chat starts with no id and gets one
/// back from the first send.
@MainActor
public final class ChatThreadStore: ObservableObject {
    public enum Phase: Equatable {
        case loading
        case loaded
        case failed(String)
    }

    /// How often the client asks the server for a running turn's progress —
    /// matches the web reader's cadence.
    private static let pollIntervalNanos: UInt64 = 600_000_000
    /// Consecutive poll transport errors tolerated before giving up on a turn.
    private static let pollErrorTolerance = 5

    @Published public private(set) var phase: Phase
    @Published public private(set) var messages: [ChatMessage] = []
    @Published public private(set) var conversationID: Int?
    @Published public private(set) var title: String
    @Published public private(set) var usageLabel: String = ""
    @Published public var composerText: String = ""
    @Published public private(set) var isSending = false

    public let api: ChatAPI
    private var pollTask: Task<Void, Never>?

    public init(api: ChatAPI, conversationID: Int?, title: String = "New chat") {
        self.api = api
        self.conversationID = conversationID
        self.title = title
        // A brand-new chat has nothing to load — it's ready for the first send.
        phase = conversationID == nil ? .loaded : .loading
    }

    deinit { pollTask?.cancel() }

    /// True while the newest turn is still being generated.
    public var isTurnRunning: Bool {
        messages.last?.status == .running
    }

    public var canSend: Bool {
        !composerText.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty && !isSending && !isTurnRunning
    }

    public func load() async {
        guard let conversationID else { phase = .loaded; return }
        phase = .loading
        do {
            let payload = try await api.thread(conversationID: conversationID)
            title = payload.conversation.title
            usageLabel = payload.conversation.usageLabel ?? ""
            messages = payload.messages
            phase = .loaded
            // A turn already in flight (opened mid-generation) keeps streaming.
            if let last = messages.last, last.role == .assistant, last.status == .running {
                startPolling(messageID: last.id)
            }
        } catch {
            phase = .failed((error as? JoineryAPIError)?.displayMessage ?? error.localizedDescription)
        }
    }

    public func send() async {
        let text = composerText.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !text.isEmpty, !isSending, !isTurnRunning else { return }
        isSending = true
        composerText = ""
        defer { isSending = false }
        do {
            let result = try await api.send(
                message: text,
                conversationID: conversationID,
                enableDataAccess: conversationID == nil
            )
            if conversationID == nil {
                conversationID = result.conversationID
                title = result.title
            }
            if let userMessage = result.userMessage {
                messages.append(userMessage)
            }
            finishOrPoll(result)
        } catch {
            messages.append(.localFailure(error: (error as? JoineryAPIError)?.displayMessage ?? "Could not send your message."))
        }
    }

    /// Approve or decline a proposed action; the same assistant row resumes.
    public func resolve(pending messageID: Int, decision: String) async {
        guard let conversationID else { return }
        setRunning(messageID)
        do {
            let result = try await api.confirm(conversationID: conversationID, messageID: messageID, decision: decision)
            finishOrPoll(result, fallbackMessageID: messageID)
        } catch {
            markFailed(messageID, error: (error as? JoineryAPIError)?.displayMessage ?? "Could not resolve the action.")
        }
    }

    public func deleteTurn(_ message: ChatMessage) async {
        do {
            let removed = try await api.deleteTurn(messageID: message.id)
            let ids = Set(removed)
            messages.removeAll { ids.contains($0.id) }
        } catch {
            // Leave the row; a reload will reconcile.
        }
    }

    // MARK: Turn delivery

    /// A synchronous fallback response carries the finished assistant turn;
    /// otherwise show a placeholder and poll the running row.
    private func finishOrPoll(_ result: ChatSendResult, fallbackMessageID: Int? = nil) {
        if let assistant = result.assistantMessage {
            upsert(assistant)
            if let usage = result.usageLabel { usageLabel = usage }
        } else if result.status == .failed {
            let id = fallbackMessageID ?? result.messageID
            markFailed(id, error: result.error ?? "The assistant could not complete this turn.", insertingIfMissing: true)
        } else {
            let id = fallbackMessageID ?? result.messageID
            if !messages.contains(where: { $0.id == id }) {
                messages.append(.runningPlaceholder(id: id))
            }
            startPolling(messageID: id)
        }
    }

    private func startPolling(messageID: Int) {
        pollTask?.cancel()
        pollTask = Task { [weak self] in
            var errorStreak = 0
            while !Task.isCancelled {
                try? await Task.sleep(nanoseconds: Self.pollIntervalNanos)
                if Task.isCancelled { return }
                guard let self else { return }
                do {
                    let result = try await self.api.poll(messageID: messageID)
                    errorStreak = 0
                    switch result.status {
                    case .running:
                        if let partial = result.partialText { self.updatePartial(messageID, text: partial) }
                    case .complete:
                        if let message = result.message { self.upsert(message) }
                        if let usage = result.usageLabel { self.usageLabel = usage }
                        return
                    case .failed:
                        self.markFailed(messageID, error: result.error ?? "The assistant could not complete this turn.")
                        return
                    }
                } catch {
                    errorStreak += 1
                    if errorStreak >= Self.pollErrorTolerance {
                        self.markFailed(messageID, error: (error as? JoineryAPIError)?.displayMessage ?? "Lost connection to the assistant.")
                        return
                    }
                }
            }
        }
    }

    // MARK: Row mutators

    private func upsert(_ message: ChatMessage) {
        if let index = messages.firstIndex(where: { $0.id == message.id }) {
            messages[index] = message
        } else {
            messages.append(message)
        }
    }

    private func updatePartial(_ id: Int, text: String) {
        guard let index = messages.firstIndex(where: { $0.id == id }) else { return }
        messages[index].content = text
        messages[index].status = .running
    }

    private func setRunning(_ id: Int) {
        guard let index = messages.firstIndex(where: { $0.id == id }) else { return }
        messages[index].status = .running
        messages[index].pendingAction = nil
    }

    private func markFailed(_ id: Int, error: String, insertingIfMissing: Bool = false) {
        if let index = messages.firstIndex(where: { $0.id == id }) {
            messages[index].status = .failed
            messages[index].error = error
        } else if insertingIfMissing {
            messages.append(.localFailure(error: error))
        }
    }
}
