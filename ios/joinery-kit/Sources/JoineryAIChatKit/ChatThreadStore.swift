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
    /// Files the user has picked for the next send, shown as removable chips in
    /// the composer.
    @Published public private(set) var pendingAttachments: [ChatOutgoingAttachment] = []
    /// A one-off notice — e.g. a file the server dropped at commit — shown above
    /// the composer until the next send clears it.
    @Published public private(set) var attachmentNotice: String = ""

    /// The per-chat controls (model, capabilities, reasoning, sampling) driving
    /// the settings sheet; seeded onto a new chat's first send.
    @Published public private(set) var controls = ChatControlValues(data: nil)
    /// The model catalog + defaults, loaded lazily when settings first opens.
    @Published public private(set) var meta: ChatControlsMeta?

    public let api: ChatAPI
    private var pollTask: Task<Void, Never>?

    /// Origin for resolving the relative signed image URLs the server returns on
    /// attachments.
    public var baseURL: URL { api.client.config.baseURL }

    /// True for a new chat whose controls haven't been seeded from the server
    /// defaults yet — the first meta load overwrites them; a later load leaves
    /// any user edits alone.
    private var controlsNeedDefaults: Bool

    public init(api: ChatAPI, conversationID: Int?, title: String = "New chat") {
        self.api = api
        self.conversationID = conversationID
        self.title = title
        // A brand-new chat has nothing to load — it's ready for the first send.
        phase = conversationID == nil ? .loaded : .loading
        controlsNeedDefaults = conversationID == nil
    }

    deinit { pollTask?.cancel() }

    /// True while the newest turn is still being generated.
    public var isTurnRunning: Bool {
        messages.last?.status == .running
    }

    public var canSend: Bool {
        let hasText = !composerText.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty
        return (hasText || !pendingAttachments.isEmpty) && !isSending && !isTurnRunning
    }

    /// Queue a picked file for the next send.
    public func addAttachment(_ attachment: ChatOutgoingAttachment) {
        pendingAttachments.append(attachment)
    }

    /// Drop a queued file before it's sent.
    public func removeAttachment(_ id: UUID) {
        pendingAttachments.removeAll { $0.id == id }
    }

    public func dismissAttachmentNotice() {
        attachmentNotice = ""
    }

    public func load() async {
        guard let conversationID else { phase = .loaded; return }
        phase = .loading
        do {
            let payload = try await api.thread(conversationID: conversationID)
            title = payload.conversation.title
            usageLabel = payload.conversation.usageLabel ?? ""
            if let loaded = payload.conversation.controls { controls = loaded }
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
        let attachments = pendingAttachments
        guard !text.isEmpty || !attachments.isEmpty, !isSending, !isTurnRunning else { return }
        isSending = true
        composerText = ""
        pendingAttachments = []
        attachmentNotice = ""
        defer { isSending = false }
        do {
            let isNew = conversationID == nil
            let result = try await api.send(
                message: text,
                conversationID: conversationID,
                seed: isNew ? controls.seedFields : [:],
                attachments: attachments
            )
            if isNew {
                conversationID = result.conversationID
                title = result.title
                controlsNeedDefaults = false   // controls are now the created chat's
            }
            if let userMessage = result.userMessage {
                messages.append(userMessage)
            }
            if let warning = result.attachmentWarning, !warning.isEmpty {
                attachmentNotice = warning
            }
            finishOrPoll(result)
        } catch {
            // Re-queue the picked files so a transient failure doesn't force the
            // user back through the picker.
            pendingAttachments = attachments
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

    // MARK: Controls

    /// Fetch the model catalog + defaults (once). For a not-yet-created chat,
    /// seed its controls from the defaults so the sheet shows real values.
    public func loadMeta() async {
        guard meta == nil else { return }
        do {
            let loaded = try await api.controls()
            meta = loaded
            if controlsNeedDefaults {
                controls = ChatControlValues(defaults: loaded.defaults)
                controlsNeedDefaults = false
            }
        } catch {
            // Leave meta nil; the sheet shows a spinner and the caller can retry.
        }
    }

    /// Update one control. Applied locally immediately; on an existing chat it
    /// also persists server-side (a new chat carries it on the first send).
    public func setControl(field: String, value: String, apply: (inout ChatControlValues) -> Void) {
        apply(&controls)
        guard let conversationID else { return }
        Task { try? await api.setControl(conversationID: conversationID, field: field, value: value) }
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
