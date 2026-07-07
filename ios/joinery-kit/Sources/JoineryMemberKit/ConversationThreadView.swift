import SwiftUI
import JoineryKit

/// One conversation: message bubbles in a scroll with a compose bar pinned
/// to the bottom, cursor pagination on scroll, and a mute/delete menu.
/// Opening the thread marks it read server-side as a side effect of the
/// load call. New-conversation compose (`origin: .compose`) dedups to an
/// existing 1:1 conversation on load, or creates one on first send.
public struct ConversationThreadView: View {
    @StateObject private var store: ConversationThreadStore
    @Environment(\.dismiss) private var dismiss
    @State private var showDeleteConfirm = false
    @State private var deleteError: String?

    public init(client: APIClient, origin: ThreadOrigin) {
        _store = StateObject(wrappedValue: ConversationThreadStore(api: ConversationAPI(client: client), origin: origin))
    }

    public var body: some View {
        content
            .navigationTitle(store.otherDisplayName)
            .navigationBarTitleDisplayMode(.inline)
            .toolbar { toolbarContent }
            .safeAreaInset(edge: .bottom) { composer }
            .task {
                if case .loading = store.phase { await store.load() }
            }
            .confirmationDialog("Delete this conversation?", isPresented: $showDeleteConfirm, titleVisibility: .visible) {
                Button("Delete", role: .destructive) {
                    Task {
                        do {
                            try await store.delete()
                            dismiss()
                        } catch {
                            deleteError = (error as? JoineryAPIError)?.displayMessage ?? "Could not delete the conversation."
                        }
                    }
                }
                Button("Cancel", role: .cancel) {}
            }
            .alert("Could not delete", isPresented: deleteErrorBinding) {
                Button("OK") {}
            } message: {
                Text(deleteError ?? "")
            }
    }

    @ViewBuilder
    private var content: some View {
        switch store.phase {
        case .loading:
            ProgressView()
                .frame(maxWidth: .infinity, maxHeight: .infinity)
                .accessibilityIdentifier("conversation_thread_loading")
        case .failed(let message):
            VStack(spacing: 12) {
                Text(message)
                    .multilineTextAlignment(.center)
                    .foregroundStyle(.secondary)
                    .accessibilityIdentifier("conversation_thread_error")
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
                LazyVStack(alignment: .leading, spacing: 10) {
                    if store.hasMore {
                        HStack {
                            Spacer()
                            if store.isLoadingMore {
                                ProgressView()
                            } else {
                                Button("Load more") { Task { await store.loadMore() } }
                                    .font(.caption)
                            }
                            Spacer()
                        }
                    }
                    if store.messages.isEmpty {
                        emptyState
                    }
                    ForEach(store.messages) { message in
                        MessageBubble(message: message)
                            .id(message.id)
                    }
                    Color.clear.frame(height: 1).id(bottomAnchor)
                }
                .padding(.horizontal, 14)
                .padding(.top, 12)
            }
            .accessibilityIdentifier("conversation_transcript")
            .onChange(of: store.messages.count) { _ in
                withAnimation(.easeOut(duration: 0.2)) { proxy.scrollTo(bottomAnchor, anchor: .bottom) }
            }
            .onAppear { proxy.scrollTo(bottomAnchor, anchor: .bottom) }
        }
    }

    private var emptyState: some View {
        Text("Say hello.")
            .foregroundStyle(.secondary)
            .frame(maxWidth: .infinity)
            .padding(.vertical, 60)
            .accessibilityIdentifier("conversation_thread_empty")
    }

    private var composer: some View {
        HStack(alignment: .bottom, spacing: 8) {
            TextField("Message", text: $store.composerText, axis: .vertical)
                .textFieldStyle(.plain)
                .lineLimit(1...5)
                .padding(.horizontal, 12)
                .padding(.vertical, 8)
                .background(RoundedRectangle(cornerRadius: 20).fill(Color(.secondarySystemBackground)))
                .accessibilityIdentifier("conversation_composer")
            Button {
                Task { await store.send() }
            } label: {
                if store.isSending {
                    ProgressView().frame(width: 32, height: 32)
                } else {
                    Image(systemName: "arrow.up.circle.fill")
                        .font(.system(size: 32))
                }
            }
            .disabled(!store.canSend)
            .accessibilityIdentifier("conversation_send")
        }
        .padding(.horizontal, 12)
        .padding(.vertical, 8)
        .background(.bar)
    }

    @ToolbarContentBuilder
    private var toolbarContent: some ToolbarContent {
        ToolbarItem(placement: .topBarTrailing) {
            Menu {
                if store.conversationID != nil {
                    Button {
                        Task { await store.setMuted(!store.isMuted) }
                    } label: {
                        Label(store.isMuted ? "Unmute" : "Mute", systemImage: store.isMuted ? "bell" : "bell.slash")
                    }
                    Button(role: .destructive) {
                        showDeleteConfirm = true
                    } label: {
                        Label("Delete", systemImage: "trash")
                    }
                }
            } label: {
                Image(systemName: "ellipsis.circle")
            }
            .accessibilityIdentifier("conversation_thread_menu")
            .disabled(store.conversationID == nil)
        }
    }

    private let bottomAnchor = "conversation_bottom_anchor"

    private var deleteErrorBinding: Binding<Bool> {
        Binding(get: { deleteError != nil }, set: { if !$0 { deleteError = nil } })
    }
}

/// One message bubble: the caller's on the right, the other participant's
/// on the left.
struct MessageBubble: View {
    let message: ThreadMessage

    var body: some View {
        HStack {
            if message.isMine { Spacer(minLength: 40) }
            VStack(alignment: message.isMine ? .trailing : .leading, spacing: 3) {
                Text(message.body)
                    .padding(.horizontal, 14)
                    .padding(.vertical, 9)
                    .background(
                        RoundedRectangle(cornerRadius: 18)
                            .fill(message.isMine ? Color.accentColor : Color(.secondarySystemBackground))
                    )
                    .foregroundStyle(message.isMine ? .white : .primary)
                    .textSelection(.enabled)
                Text(MemberDisplay.dateTimeLabel(message.time))
                    .font(.caption2)
                    .foregroundStyle(.tertiary)
            }
            .accessibilityIdentifier(message.isMine ? "conversation_message_mine" : "conversation_message_theirs")
            if !message.isMine { Spacer(minLength: 40) }
        }
    }
}
