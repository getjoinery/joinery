import SwiftUI
import JoineryKit

/// The mailbox's phone-side settings (specs/fortress_mobile_apps.md § R3, R5,
/// R13, R15): the mail key on this phone, search on this phone, the person's
/// own AI model, and which mailboxes notify.
struct MailSettingsView: View {
    let model: MailScreenModel
    @ObservedObject private var vault: DeviceVault
    @ObservedObject private var ai: DeviceAISettings
    @State private var apiKey = ""
    @State private var testResult: String?
    @State private var testing = false
    @State private var idleMinutes: Int
    @State private var notifyOn: [Int: Bool] = [:]
    @State private var searchLine: String?
    @Environment(\.dismiss) private var dismiss

    init(model: MailScreenModel) {
        self.model = model
        _vault = ObservedObject(wrappedValue: model.fortress.vault)
        _ai = ObservedObject(wrappedValue: model.ai)
        _idleMinutes = State(initialValue: model.fortress.session.heldKeys.idleMinutes)
    }

    private var mailboxes: [Mailbox] { model.store.home?.mailboxes ?? [] }
    private var hasFortress: Bool { mailboxes.contains(where: \.isFortress) }

    var body: some View {
        NavigationStack {
            Form {
                if hasFortress { keySection; searchSection; aiSection }
                notifySection
            }
            .navigationTitle("Mail settings")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar { ToolbarItem(placement: .topBarTrailing) { Button("Done") { dismiss() } } }
            .onAppear {
                for b in mailboxes { notifyOn[b.aliasID] = MailPolling.isOn(b) }
                searchLine = model.store.deviceSearch?.status.line
            }
        }
    }

    private var keySection: some View {
        Section {
            switch vault.status {
            case .open: Label("Your mail key is on this phone and open.", systemImage: "lock.open")
            case .locked: Label("Your mail key is on this phone, locked behind Face ID or Touch ID.", systemImage: "lock")
            case .notEnrolled: Label("This phone does not hold your mail key.", systemImage: "lock.slash")
            }
            Stepper("Lock after \(idleMinutes) minute\(idleMinutes == 1 ? "" : "s") in the background",
                    value: $idleMinutes, in: 1...60)
                .onChange(of: idleMinutes) { model.fortress.session.heldKeys.idleMinutes = $0 }
            if vault.status == .open {
                Button("Lock now") { model.fortress.lock() }
            }
            if vault.status != .notEnrolled {
                Button("Remove the key from this phone", role: .destructive) { vault.forget() }
            }
        } header: {
            Text("End-to-end mail")
        } footer: {
            Text("Your phone reads end-to-end mail once you hand it the key from a computer; the key stays behind your phone's face or fingerprint lock. A stolen phone yields nothing without your face or finger.")
        }
    }

    private var searchSection: some View {
        Section {
            if let line = searchLine { Text(line).font(.footnote) }
            Button("Rebuild the index") {
                Task { await model.store.deviceSearch?.rebuild(); searchLine = model.store.deviceSearch?.status.line }
            }.disabled(vault.status != .open)
            Button("Remove from this phone", role: .destructive) {
                model.store.deviceSearch?.remove()
                searchLine = nil
            }
        } header: {
            Text("Search on this phone")
        } footer: {
            Text("Your site cannot read end-to-end mail, so this phone keeps its own sealed index to search it.")
        }
    }

    private var aiSection: some View {
        Section {
            TextField("Address, e.g. https://ollama.example.ts.net/v1", text: $ai.baseURL)
                .keyboardType(.URL).textInputAutocapitalization(.never).autocorrectionDisabled()
                .accessibilityIdentifier("mail_ai_base")
            TextField("Model name", text: $ai.model)
                .textInputAutocapitalization(.never).autocorrectionDisabled()
                .accessibilityIdentifier("mail_ai_model")
            SecureField("API key (if your model needs one)", text: $apiKey)
                .accessibilityIdentifier("mail_ai_key")
            Button("Save key") {
                try? ai.setKey(apiKey, held: model.fortress.session.heldKeys)
                apiKey = ""
            }.disabled(vault.status != .open)
            Toggle("Wi-Fi only", isOn: $ai.wifiOnly)
            Button(testing ? "Testing…" : "Test") {
                testing = true
                Task {
                    testResult = await ai.test(api: model.store.api, held: model.fortress.session.heldKeys,
                                               mailbox: mailboxes.first { $0.isFortress && $0.own }?.address)
                    testing = false
                }
            }.disabled(testing).accessibilityIdentifier("mail_ai_test")
            if let testResult { Text(testResult).font(.footnote).accessibilityIdentifier("mail_ai_test_result") }
        } header: {
            Text("Your AI model")
        } footer: {
            Text("Summaries and security scans of end-to-end mail run on a model you own, from this phone, while your mail is open. The model's address is registered on the Email settings page on a computer; its key stays behind your phone's lock. With Wi-Fi only on, message bodies never go over cellular.")
        }
    }

    private var notifySection: some View {
        Section {
            ForEach(mailboxes) { box in
                Toggle(box.address, isOn: Binding(
                    get: { notifyOn[box.aliasID] ?? MailPolling.isOn(box) },
                    set: { on in
                        notifyOn[box.aliasID] = on
                        Task { await MailPolling.set(box, on: on, all: mailboxes) }
                    }))
                .accessibilityIdentifier("mail_notify_\(box.aliasID)")
            }
        } header: {
            Text("New-mail notifications")
        } footer: {
            Text("New mail shows when your phone next checks, which it decides. End-to-end and Private mailboxes say only which mailbox has new mail.")
        }
    }
}
