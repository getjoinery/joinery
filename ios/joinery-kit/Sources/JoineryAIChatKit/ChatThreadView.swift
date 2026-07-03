import SwiftUI
import JoineryKit

/// One conversation: the turns in a scroll with a composer pinned to the
/// bottom. A running turn streams its answer via the store's poll loop; a turn
/// that proposes an action shows a Confirm / Cancel card.
struct ChatThreadView: View {
    @StateObject private var store: ChatThreadStore

    init(api: ChatAPI, conversationID: Int?, title: String) {
        _store = StateObject(wrappedValue: ChatThreadStore(api: api, conversationID: conversationID, title: title))
    }

    var body: some View {
        content
            .navigationTitle(store.title)
            .navigationBarTitleDisplayMode(.inline)
            .safeAreaInset(edge: .bottom) { composer }
            .task {
                if case .loading = store.phase { await store.load() }
            }
    }

    @ViewBuilder
    private var content: some View {
        switch store.phase {
        case .loading:
            ProgressView()
                .frame(maxWidth: .infinity, maxHeight: .infinity)
                .accessibilityIdentifier("chat_thread_loading")
        case .failed(let message):
            VStack(spacing: 12) {
                Text(message)
                    .multilineTextAlignment(.center)
                    .foregroundStyle(.secondary)
                    .accessibilityIdentifier("chat_thread_error")
                Button("Try Again") { Task { await store.load() } }
                    .buttonStyle(.borderedProminent)
            }
            .padding()
        case .loaded:
            transcript
        }
    }

    private var transcript: some View {
        ScrollViewReader { proxy in
            ScrollView {
                LazyVStack(alignment: .leading, spacing: 14) {
                    if store.messages.isEmpty {
                        emptyState
                    }
                    ForEach(store.messages) { message in
                        MessageRow(message: message) { decision in
                            Task { await store.resolve(pending: message.id, decision: decision) }
                        } onDelete: {
                            Task { await store.deleteTurn(message) }
                        }
                        .id(message.id)
                    }
                    // Anchor so we can pin the view to the newest content.
                    Color.clear.frame(height: 1).id(bottomAnchor)
                }
                .padding(.horizontal, 14)
                .padding(.top, 12)
            }
            .accessibilityIdentifier("chat_transcript")
            .onChange(of: store.messages.count) { _ in scrollToBottom(proxy) }
            .onChange(of: lastContentLength) { _ in scrollToBottom(proxy) }
            .onAppear { scrollToBottom(proxy, animated: false) }
        }
    }

    private var emptyState: some View {
        VStack(spacing: 8) {
            Image(systemName: "sparkles")
                .font(.largeTitle)
                .foregroundStyle(.secondary)
            Text("Ask the assistant anything.")
                .foregroundStyle(.secondary)
        }
        .frame(maxWidth: .infinity)
        .padding(.vertical, 80)
        .accessibilityIdentifier("chat_thread_empty")
    }

    private var composer: some View {
        VStack(spacing: 6) {
            if !store.usageLabel.isEmpty {
                Text(store.usageLabel)
                    .font(.caption2)
                    .foregroundStyle(.tertiary)
                    .frame(maxWidth: .infinity, alignment: .center)
            }
            HStack(alignment: .bottom, spacing: 8) {
                TextField("Message", text: $store.composerText, axis: .vertical)
                    .textFieldStyle(.plain)
                    .lineLimit(1...5)
                    .padding(.horizontal, 12)
                    .padding(.vertical, 8)
                    .background(
                        RoundedRectangle(cornerRadius: 20)
                            .fill(Color(.secondarySystemBackground))
                    )
                    .accessibilityIdentifier("chat_composer")
                Button {
                    Task { await store.send() }
                } label: {
                    if store.isSending || store.isTurnRunning {
                        ProgressView()
                            .frame(width: 32, height: 32)
                    } else {
                        Image(systemName: "arrow.up.circle.fill")
                            .font(.system(size: 32))
                    }
                }
                .disabled(!store.canSend)
                .accessibilityIdentifier("chat_send")
            }
            .padding(.horizontal, 12)
            .padding(.vertical, 8)
        }
        .background(.bar)
    }

    // Grows as the running turn streams, driving the auto-scroll.
    private var lastContentLength: Int {
        store.messages.last?.content.count ?? 0
    }

    private let bottomAnchor = "chat_bottom_anchor"

    private func scrollToBottom(_ proxy: ScrollViewProxy, animated: Bool = true) {
        guard !store.messages.isEmpty else { return }
        if animated {
            withAnimation(.easeOut(duration: 0.2)) { proxy.scrollTo(bottomAnchor, anchor: .bottom) }
        } else {
            proxy.scrollTo(bottomAnchor, anchor: .bottom)
        }
    }
}

/// One turn. The user's on the right; the assistant on the left with markdown,
/// an optional tool trace, a running indicator, an error, or a confirm card.
struct MessageRow: View {
    let message: ChatMessage
    let onDecision: (String) -> Void
    let onDelete: () -> Void

    var body: some View {
        if message.role == .user {
            userBubble
        } else {
            assistantBubble
        }
    }

    private var userBubble: some View {
        HStack {
            Spacer(minLength: 40)
            Text(message.content)
                .padding(.horizontal, 14)
                .padding(.vertical, 9)
                .background(RoundedRectangle(cornerRadius: 18).fill(Color.accentColor))
                .foregroundStyle(.white)
                .textSelection(.enabled)
        }
        .contextMenu { deleteButton }
        .accessibilityIdentifier("chat_user_message")
    }

    private var assistantBubble: some View {
        VStack(alignment: .leading, spacing: 8) {
            if message.status == .failed {
                Label(message.error.isEmpty ? "The assistant could not complete this turn." : message.error,
                      systemImage: "exclamationmark.triangle")
                    .font(.subheadline)
                    .foregroundStyle(.red)
            } else if message.content.isEmpty && message.status == .running {
                TypingIndicator()
            } else {
                MarkdownText(message.content)
                    .textSelection(.enabled)
            }

            if !message.toolCalls.isEmpty {
                toolTrace
            }

            if let pending = message.pendingAction {
                ConfirmCard(description: pending.description, onDecision: onDecision)
            }

            HStack(spacing: 8) {
                if message.status == .running && !message.content.isEmpty {
                    ProgressView().scaleEffect(0.7)
                }
                if !message.costLabel.isEmpty {
                    Text(message.costLabel)
                        .font(.caption2)
                        .foregroundStyle(.tertiary)
                }
            }
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .padding(.trailing, 24)
        .contextMenu { deleteButton }
        .accessibilityIdentifier("chat_assistant_message")
    }

    private var toolTrace: some View {
        DisclosureGroup {
            VStack(alignment: .leading, spacing: 3) {
                ForEach(Array(message.toolCalls.enumerated()), id: \.offset) { _, call in
                    HStack(spacing: 6) {
                        Image(systemName: call.isError ? "xmark.circle" : "checkmark.circle")
                            .foregroundStyle(call.isError ? .red : .secondary)
                        Text(call.name)
                        if let ms = call.durationMs {
                            Text("· \(ms)ms").foregroundStyle(.tertiary)
                        }
                    }
                    .font(.caption)
                }
            }
            .padding(.top, 2)
        } label: {
            Text("\(message.toolCalls.count) tool call\(message.toolCalls.count == 1 ? "" : "s")")
                .font(.caption)
                .foregroundStyle(.secondary)
        }
    }

    private var deleteButton: some View {
        Button(role: .destructive, action: onDelete) {
            Label("Delete", systemImage: "trash")
        }
    }
}

/// The assistant's "confirm before I do this" card for a held mutating action.
struct ConfirmCard: View {
    let description: String
    let onDecision: (String) -> Void

    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            Text(description)
                .font(.subheadline)
            HStack(spacing: 10) {
                Button("Confirm") { onDecision("confirm") }
                    .buttonStyle(.borderedProminent)
                    .accessibilityIdentifier("chat_confirm_yes")
                Button("Cancel") { onDecision("cancel") }
                    .buttonStyle(.bordered)
                    .accessibilityIdentifier("chat_confirm_no")
            }
        }
        .padding(12)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(RoundedRectangle(cornerRadius: 12).fill(Color(.secondarySystemBackground)))
        .overlay(RoundedRectangle(cornerRadius: 12).strokeBorder(Color.accentColor.opacity(0.4)))
        .accessibilityIdentifier("chat_confirm_card")
    }
}

/// A three-dot "thinking" indicator shown while a turn runs before any text
/// has streamed back.
struct TypingIndicator: View {
    @State private var phase = 0.0

    var body: some View {
        HStack(spacing: 5) {
            ForEach(0..<3) { i in
                Circle()
                    .fill(Color.secondary)
                    .frame(width: 7, height: 7)
                    .opacity(phase == Double(i) ? 1.0 : 0.35)
            }
        }
        .accessibilityIdentifier("chat_typing")
        .onAppear {
            withAnimation(.easeInOut(duration: 0.5).repeatForever(autoreverses: true)) {
                phase = 2
            }
        }
    }
}

/// Renders assistant markdown block by block: headings, bullet rows, and
/// horizontal rules become native layout; every other line renders with inline
/// formatting (bold/italic/links/code). Enough for chat replies without pulling
/// in a full CommonMark renderer.
struct MarkdownText: View {
    let raw: String

    init(_ raw: String) { self.raw = raw }

    var body: some View {
        VStack(alignment: .leading, spacing: 5) {
            ForEach(Array(blocks.enumerated()), id: \.offset) { _, block in
                block.view
            }
        }
    }

    private var blocks: [MarkdownBlock] {
        raw.components(separatedBy: "\n").map(MarkdownBlock.init(line:))
    }
}

/// One rendered line of assistant markdown.
private struct MarkdownBlock {
    enum Kind {
        case heading(level: Int, text: String)
        case bullet(String)
        case rule
        case blank
        case text(String)
    }

    let kind: Kind

    init(line: String) {
        let trimmed = line.trimmingCharacters(in: .whitespaces)
        if trimmed.isEmpty {
            kind = .blank
        } else if trimmed == "---" || trimmed == "***" || trimmed == "___" {
            kind = .rule
        } else if trimmed.hasPrefix("### ") {
            kind = .heading(level: 3, text: String(trimmed.dropFirst(4)))
        } else if trimmed.hasPrefix("## ") {
            kind = .heading(level: 2, text: String(trimmed.dropFirst(3)))
        } else if trimmed.hasPrefix("# ") {
            kind = .heading(level: 1, text: String(trimmed.dropFirst(2)))
        } else if trimmed.hasPrefix("- ") || trimmed.hasPrefix("* ") || trimmed.hasPrefix("+ ") {
            kind = .bullet(String(trimmed.dropFirst(2)))
        } else {
            kind = .text(line)
        }
    }

    @ViewBuilder
    var view: some View {
        switch kind {
        case .blank:
            Color.clear.frame(height: 2)
        case .rule:
            Divider().padding(.vertical, 2)
        case .heading(let level, let text):
            inline(text)
                .font(level <= 1 ? .title3.bold() : (level == 2 ? .headline : .subheadline.bold()))
                .padding(.top, 2)
        case .bullet(let text):
            HStack(alignment: .top, spacing: 7) {
                Text("•").foregroundStyle(.secondary)
                inline(text)
            }
        case .text(let text):
            inline(text)
        }
    }

    private func inline(_ string: String) -> Text {
        let attributed = (try? AttributedString(
            markdown: string,
            options: .init(interpretedSyntax: .inlineOnlyPreservingWhitespace)
        )) ?? AttributedString(string)
        return Text(attributed)
    }
}
