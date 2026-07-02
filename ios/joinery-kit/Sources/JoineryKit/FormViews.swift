import SwiftUI

/// Generic server-driven form screen: fetches `GET /api/v1/form/{action}`,
/// renders the definition with the shared field renderer, submits to
/// `POST /api/v1/action/{action}`, and maps field errors back onto controls.
/// Every account form in every Joinery app is this one screen.
public struct FormScreen: View {
    let client: APIClient
    let action: String
    let query: [URLQueryItem]
    let authenticated: Bool
    let onSuccess: (JSONValue) -> Void

    @State private var phase: Phase = .loading

    enum Phase {
        case loading
        case failed(String)
        /// Definition contains something this build can't render — the web
        /// surface is the fallback (webview wiring lands in Phase 3).
        case unsupported
        case ready(FormState)
    }

    public init(
        client: APIClient,
        action: String,
        query: [URLQueryItem] = [],
        authenticated: Bool = true,
        onSuccess: @escaping (JSONValue) -> Void = { _ in }
    ) {
        self.client = client
        self.action = action
        self.query = query
        self.authenticated = authenticated
        self.onSuccess = onSuccess
    }

    public var body: some View {
        Group {
            switch phase {
            case .loading:
                ProgressView()
                    .accessibilityIdentifier("form_loading")
            case .failed(let message):
                VStack(spacing: 12) {
                    Text(message)
                        .multilineTextAlignment(.center)
                        .accessibilityIdentifier("form_load_error")
                    Button("Try Again") {
                        phase = .loading
                        Task { await load() }
                    }
                }
                .padding()
            case .unsupported:
                VStack(spacing: 12) {
                    Image(systemName: "arrow.up.forward.app")
                        .font(.largeTitle)
                    Text("This form needs a newer version of the app. Please update, or use the website.")
                        .multilineTextAlignment(.center)
                        .accessibilityIdentifier("form_unsupported")
                }
                .padding()
            case .ready(let state):
                FormBodyView(state: state, client: client, authenticated: authenticated, onSuccess: onSuccess)
            }
        }
        .task { await load() }
    }

    private func load() async {
        do {
            let definition = try await client.formDefinition(action: action, query: query, authenticated: authenticated)
            if definition.isRenderable {
                phase = .ready(FormState(definition: definition))
            } else {
                phase = .unsupported
            }
        } catch let error as JoineryAPIError {
            phase = .failed(error.displayMessage)
        } catch {
            phase = .failed("Could not load the form.")
        }
    }
}

/// The rendered form: shared by FormScreen and the password-reset flow.
struct FormBodyView: View {
    @ObservedObject var state: FormState
    let client: APIClient
    let authenticated: Bool
    let onSuccess: (JSONValue) -> Void

    @State private var submitting = false
    @State private var successMessage: String?

    var body: some View {
        Form {
            if let formError = state.formError {
                Section {
                    Text(formError)
                        .foregroundStyle(.red)
                        .accessibilityIdentifier("form_error")
                }
            }
            if let successMessage {
                Section {
                    Label(successMessage, systemImage: "checkmark.circle")
                        .foregroundStyle(.green)
                        .accessibilityIdentifier("form_success")
                }
            }
            ForEach(state.definition.fields.filter { state.isVisible($0) }, id: \.name) { field in
                Section(footer: fieldFooter(field)) {
                    FormFieldView(field: field, state: state)
                }
            }
            Section {
                Button {
                    Task { await submit() }
                } label: {
                    if submitting {
                        ProgressView()
                    } else {
                        Text(state.definition.submitLabel)
                    }
                }
                .disabled(submitting)
                .accessibilityIdentifier("form_submit")
            }
        }
    }

    @ViewBuilder
    private func fieldFooter(_ field: FormField) -> some View {
        VStack(alignment: .leading, spacing: 4) {
            if let error = state.fieldErrors[field.name] {
                Text(error)
                    .foregroundStyle(.red)
                    .accessibilityIdentifier("\(field.name)_error")
            }
            if !field.helptext.isEmpty {
                Text(field.helptext)
            }
        }
    }

    private func submit() async {
        state.formError = nil
        successMessage = nil
        guard state.validateForSubmit() else { return }
        submitting = true
        defer { submitting = false }
        do {
            let envelope = try await client.submitAction(
                actionName(from: state.definition.submitTo),
                body: state.submissionBody(),
                authenticated: authenticated
            )
            state.fieldErrors = [:]
            successMessage = envelope["success_message"]?.stringValue.flatMap { $0.isEmpty ? nil : $0 } ?? "Saved."
            onSuccess(envelope)
        } catch let error as JoineryAPIError {
            state.apply(error: error)
        } catch {
            state.formError = "Something went wrong. Please try again."
        }
    }

    /// `submit_to` is `/api/v1/action/{action}` (possibly plugin-namespaced);
    /// strip the prefix for submitAction, which re-adds it.
    private func actionName(from submitTo: String) -> String {
        let prefix = "/api/v1/action/"
        if submitTo.hasPrefix(prefix) {
            return String(submitTo.dropFirst(prefix.count))
        }
        return state.definition.name
    }
}

/// One field row. Accessibility identifiers equal field names so XCUITests
/// and support screenshots address controls stably.
struct FormFieldView: View {
    let field: FormField
    @ObservedObject var state: FormState

    var body: some View {
        switch field.type {
        case .text:
            LabeledContent(field.label) {
                TextField(field.placeholder, text: binding)
                    .multilineTextAlignment(.trailing)
                    .keyboardType(keyboardType)
                    .textInputAutocapitalization(autocapitalization)
                    .autocorrectionDisabled(isEmailLike)
                    .disabled(field.readonly || field.disabled)
                    .accessibilityIdentifier(field.name)
            }
        case .password:
            LabeledContent(field.label) {
                SecureField("", text: binding)
                    .multilineTextAlignment(.trailing)
                    .disabled(field.readonly || field.disabled)
                    .accessibilityIdentifier(field.name)
            }
        case .number:
            LabeledContent(field.label) {
                TextField(field.placeholder, text: binding)
                    .multilineTextAlignment(.trailing)
                    .keyboardType(.numbersAndPunctuation)
                    .disabled(field.readonly || field.disabled)
                    .accessibilityIdentifier(field.name)
            }
        case .textarea:
            VStack(alignment: .leading) {
                Text(field.label)
                TextEditor(text: binding)
                    .frame(minHeight: 88)
                    .disabled(field.readonly || field.disabled)
                    .accessibilityIdentifier(field.name)
            }
        case .drop:
            Picker(field.label, selection: binding) {
                if let empty = field.emptyOption {
                    Text(empty).tag("")
                }
                ForEach(field.options, id: \.value) { option in
                    Text(option.label).tag(option.value)
                }
            }
            .disabled(field.readonly || field.disabled)
            .accessibilityIdentifier(field.name)
            .onChange(of: state.values[field.name]) { _ in state.evaluateVisibility() }
        case .checkbox:
            Toggle(field.label, isOn: checkboxBinding)
                .disabled(field.readonly || field.disabled)
                .accessibilityIdentifier(field.name)
        case .radio:
            Picker(field.label, selection: binding) {
                ForEach(field.options, id: \.value) { option in
                    Text(option.label).tag(option.value)
                }
            }
            .pickerStyle(.inline)
            .disabled(field.readonly || field.disabled)
            .accessibilityIdentifier(field.name)
            .onChange(of: state.values[field.name]) { _ in state.evaluateVisibility() }
        case .checkboxList:
            if field.listType == "radio" {
                Picker(field.label, selection: binding) {
                    ForEach(field.options, id: \.value) { option in
                        Text(option.label).tag(option.value)
                    }
                }
                .pickerStyle(.inline)
                .accessibilityIdentifier(field.name)
                .onChange(of: state.values[field.name]) { _ in state.evaluateVisibility() }
            } else {
                VStack(alignment: .leading, spacing: 8) {
                    if !field.label.isEmpty {
                        Text(field.label)
                    }
                    ForEach(field.options, id: \.value) { option in
                        Toggle(option.label, isOn: listBinding(option.value))
                            .disabled(field.disabledValues.contains(option.value))
                            .accessibilityIdentifier("\(field.name)_\(option.value)")
                    }
                }
            }
        case .date:
            DatePicker(field.label, selection: dateBinding(format: "yyyy-MM-dd"), displayedComponents: .date)
                .disabled(field.readonly || field.disabled)
                .accessibilityIdentifier(field.name)
        case .time:
            DatePicker(field.label, selection: dateBinding(format: "HH:mm"), displayedComponents: .hourAndMinute)
                .disabled(field.readonly || field.disabled)
                .accessibilityIdentifier(field.name)
        case .datetime:
            DatePicker(field.label, selection: datetimeBinding, displayedComponents: [.date, .hourAndMinute])
                .disabled(field.readonly || field.disabled)
                .accessibilityIdentifier(field.name)
        case .hidden:
            EmptyView()
        case .unknown:
            // FormDefinition.isRenderable gates whole forms; an unknown type
            // reaching here means the caller skipped the gate — stay honest.
            Text("Unsupported field: \(field.name)")
                .foregroundStyle(.secondary)
        }
    }

    // MARK: Bindings

    private var binding: Binding<String> {
        Binding(
            get: { state.values[field.name] ?? "" },
            set: { state.values[field.name] = $0 }
        )
    }

    private var checkboxBinding: Binding<Bool> {
        Binding(
            get: { !(state.values[field.name] ?? "").isEmpty },
            set: {
                state.values[field.name] = $0 ? field.checkedValue : ""
                state.evaluateVisibility()
            }
        )
    }

    private func listBinding(_ optionValue: String) -> Binding<Bool> {
        Binding(
            get: { (state.listValues[field.name] ?? []).contains(optionValue) },
            set: { on in
                var current = state.listValues[field.name] ?? []
                if on {
                    if !current.contains(optionValue) { current.append(optionValue) }
                } else {
                    current.removeAll { $0 == optionValue }
                }
                state.listValues[field.name] = current
            }
        )
    }

    private func dateBinding(format: String) -> Binding<Date> {
        let formatter = DateFormatter()
        formatter.locale = Locale(identifier: "en_US_POSIX")
        formatter.dateFormat = format
        return Binding(
            get: {
                if let raw = state.values[field.name], let d = formatter.date(from: raw) {
                    return d
                }
                return Date()
            },
            set: { state.values[field.name] = formatter.string(from: $0) }
        )
    }

    private var datetimeBinding: Binding<Date> {
        Binding(
            get: { state.dateValues[field.name] ?? Date() },
            set: { state.dateValues[field.name] = $0 }
        )
    }

    // MARK: Input affordances

    private var isEmailLike: Bool {
        field.inputType == "email" || field.inputType == "url"
    }

    private var keyboardType: UIKeyboardType {
        switch field.inputType {
        case "email": return .emailAddress
        case "url": return .URL
        case "tel": return .phonePad
        default: return .default
        }
    }

    private var autocapitalization: TextInputAutocapitalization {
        isEmailLike ? .never : .sentences
    }
}
