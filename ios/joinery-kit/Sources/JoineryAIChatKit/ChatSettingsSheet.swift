import SwiftUI
import JoineryKit

/// Per-chat settings: model, capability toggles, reasoning effort, and sampling
/// / instructions. Picker and toggle changes apply immediately (persisted for
/// an existing chat, seeded onto a new chat's first send); the free-text fields
/// commit on Done. The same controls and validator the web status strip uses.
struct ChatSettingsSheet: View {
    @ObservedObject var store: ChatThreadStore
    @Environment(\.dismiss) private var dismiss

    // Free-text fields commit on Done rather than each keystroke.
    @State private var temperature = ""
    @State private var topP = ""
    @State private var maxTokens = ""
    @State private var instructions = ""

    private let thinkingLevels = ["off", "low", "medium", "high"]

    var body: some View {
        NavigationStack {
            Group {
                if let meta = store.meta {
                    form(meta)
                } else {
                    ProgressView()
                        .frame(maxWidth: .infinity, maxHeight: .infinity)
                        .accessibilityIdentifier("chat_settings_loading")
                }
            }
            .navigationTitle("Chat settings")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .confirmationAction) {
                    Button("Done") { commitText(); dismiss() }
                        .accessibilityIdentifier("chat_settings_done")
                }
            }
        }
        .task {
            await store.loadMeta()
            syncText()
        }
    }

    private func form(_ meta: ChatControlsMeta) -> some View {
        Form {
            Section {
                Picker("Model", selection: modelBinding) {
                    ForEach(meta.models) { model in
                        Text(model.label).tag(model.id)
                    }
                }
                .pickerStyle(.navigationLink)
                .accessibilityIdentifier("chat_set_model")
            } header: {
                Text("Model")
            } footer: {
                if !meta.isPrivate(store.controls.model) {
                    Text("This model isn't private — avoid sending sensitive personal data.")
                }
            }

            Section {
                Toggle("Data access", isOn: toggleBinding(
                    "data_access", get: { store.controls.dataAccess }, set: { $0.dataAccess = $1 }))
                    .accessibilityIdentifier("chat_set_data_access")
                Toggle("Web search", isOn: toggleBinding(
                    "web_search", get: { store.controls.webSearch }, set: { $0.webSearch = $1 }))
                    .disabled(!meta.webSearchAvailable)
                    .accessibilityIdentifier("chat_set_web_search")
            } header: {
                Text("Capabilities")
            } footer: {
                Text("Data access lets the assistant read your data and, with your confirmation, make changes.")
            }

            Section("Reasoning") {
                Picker("Thinking", selection: thinkingBinding) {
                    ForEach(thinkingLevels, id: \.self) { level in
                        Text(level.capitalized).tag(level)
                    }
                }
                .accessibilityIdentifier("chat_set_thinking")
            }

            Section {
                numberField("Temperature", text: $temperature, placeholder: meta.defaults.temperature, keyboard: .decimalPad)
                numberField("Top-p", text: $topP, placeholder: meta.defaults.topP, keyboard: .decimalPad)
                numberField("Max tokens", text: $maxTokens, placeholder: meta.defaults.maxTokens, keyboard: .numberPad)
            } header: {
                Text("Sampling")
            } footer: {
                Text("Leave blank to use the default.")
            }

            Section("Instructions") {
                TextField("Custom instructions (optional)", text: $instructions, axis: .vertical)
                    .lineLimit(3...8)
                    .accessibilityIdentifier("chat_set_instructions")
            }
        }
    }

    private func numberField(_ label: String, text: Binding<String>, placeholder: String, keyboard: UIKeyboardType) -> some View {
        HStack {
            Text(label)
            Spacer()
            TextField(placeholder.isEmpty ? "default" : placeholder, text: text)
                .keyboardType(keyboard)
                .multilineTextAlignment(.trailing)
                .frame(maxWidth: 120)
        }
    }

    // MARK: Bindings (immediate commit)

    private var modelBinding: Binding<String> {
        Binding(
            get: { store.controls.model.isEmpty ? (store.meta?.defaults.model ?? "") : store.controls.model },
            set: { value in store.setControl(field: "model", value: value) { $0.model = value } }
        )
    }

    private var thinkingBinding: Binding<String> {
        Binding(
            get: { store.controls.thinkingLevel },
            set: { value in store.setControl(field: "thinking_level", value: value) { $0.thinkingLevel = value } }
        )
    }

    private func toggleBinding(_ field: String, get: @escaping () -> Bool,
                               set: @escaping (inout ChatControlValues, Bool) -> Void) -> Binding<Bool> {
        Binding(
            get: get,
            set: { on in store.setControl(field: field, value: on ? "1" : "0") { set(&$0, on) } }
        )
    }

    // MARK: Free-text commit

    private func syncText() {
        temperature = store.controls.temperature
        topP = store.controls.topP
        maxTokens = store.controls.maxTokens
        instructions = store.controls.instructions
    }

    private func commitText() {
        commit("temperature", temperature, current: store.controls.temperature) { $0.temperature = temperature }
        commit("top_p", topP, current: store.controls.topP) { $0.topP = topP }
        commit("max_tokens", maxTokens, current: store.controls.maxTokens) { $0.maxTokens = maxTokens }
        commit("instructions", instructions, current: store.controls.instructions) { $0.instructions = instructions }
    }

    private func commit(_ field: String, _ value: String, current: String,
                        _ apply: @escaping (inout ChatControlValues) -> Void) {
        guard value != current else { return }
        store.setControl(field: field, value: value, apply: apply)
    }
}
