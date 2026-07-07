import SwiftUI
import JoineryKit
import CoreImage
import CoreImage.CIFilterBuiltins
import UIKit

/// App sessions + two-factor authentication, natively. Passkeys and the
/// Sealed Vault stay web-managed here — WKWebView cannot expose platform
/// WebAuthn, so native passkey/vault management is a separate future spec
/// (specs/mobile_native_member_screens.md § Deliberately web). Revoking the
/// session that made the request signs the app out through the same path a
/// server-side 401 uses.
public struct SecurityScreen: View {
    @StateObject private var store: SecurityStore
    @ObservedObject private var session: SessionController
    private let web: WebSessionCoordinator?
    @State private var pendingRevoke: AppSessionRow?
    @State private var showRevokeAll = false
    @State private var showDisableSheet = false
    @State private var showRegenerateSheet: [String]?

    public init(session: SessionController, web: WebSessionCoordinator?) {
        self.session = session
        self.web = web
        let store = SecurityStore(api: SecurityAPI(client: session.client))
        _store = StateObject(wrappedValue: store)
    }

    public var body: some View {
        content
            .navigationTitle("Security")
            .navigationBarTitleDisplayMode(.inline)
            .task {
                store.onSelfRevoked = { [session] in await session.logout() }
                if case .loading = store.phase { await store.initialLoad() }
            }
            .sheet(isPresented: setupSheetBinding) {
                TOTPSetupSheet(store: store)
            }
            .sheet(isPresented: $showDisableSheet) {
                TOTPDisableSheet(store: store, isPresented: $showDisableSheet)
            }
            .sheet(item: regenerateSheetBinding) { codes in
                BackupCodesSheet(codes: codes.codes, title: "New Backup Codes") {
                    showRegenerateSheet = nil
                }
            }
            .confirmationDialog(
                "Sign this device out?", isPresented: revokeDialogBinding, titleVisibility: .visible
            ) {
                Button("Sign Out Device", role: .destructive) {
                    if let target = pendingRevoke {
                        Task { await store.revoke(target) }
                    }
                    pendingRevoke = nil
                }
                Button("Cancel", role: .cancel) { pendingRevoke = nil }
            }
            .confirmationDialog(
                "Sign out every device, including this one?", isPresented: $showRevokeAll, titleVisibility: .visible
            ) {
                Button("Sign Out All Devices", role: .destructive) {
                    Task { await store.revokeAll() }
                }
                Button("Cancel", role: .cancel) {}
            }
    }

    @ViewBuilder
    private var content: some View {
        switch store.phase {
        case .loading:
            ProgressView()
                .frame(maxWidth: .infinity, maxHeight: .infinity)
                .accessibilityIdentifier("security_loading")
        case .failed(let message):
            VStack(spacing: 12) {
                Text(message)
                    .multilineTextAlignment(.center)
                    .foregroundStyle(.secondary)
                    .accessibilityIdentifier("security_error")
                Button("Try Again") { Task { await store.initialLoad() } }
                    .buttonStyle(.borderedProminent)
                    .accessibilityIdentifier("security_retry")
            }
            .padding()
        case .loaded:
            if let overview = store.overview {
                list(overview)
            }
        }
    }

    private func list(_ overview: SecurityOverview) -> some View {
        List {
            totpSection(overview)
            appSessionsSection(overview)
            passkeyVaultSection(overview)
        }
        .accessibilityIdentifier("security_list")
        .refreshable { await store.reload() }
    }

    // MARK: TOTP

    private func totpSection(_ overview: SecurityOverview) -> some View {
        Section {
            if overview.totpEnabled {
                LabeledContent("Status", value: "Enabled")
                LabeledContent("Backup Codes Remaining", value: "\(overview.backupCodesRemaining)")
                Button("Regenerate Backup Codes") {
                    Task {
                        if let codes = await store.regenerateBackupCodes() {
                            showRegenerateSheet = codes
                        }
                    }
                }
                .accessibilityIdentifier("security_regenerate_codes")
                Button("Disable Two-Factor Authentication", role: .destructive) {
                    showDisableSheet = true
                }
                .accessibilityIdentifier("security_disable_totp")
            } else {
                LabeledContent("Status", value: "Not enabled")
                Button("Enable Two-Factor Authentication") {
                    Task { await store.startSetup() }
                }
                .accessibilityIdentifier("security_enable_totp")
            }
        } header: {
            Text("Two-Factor Authentication")
        }
    }

    // MARK: App sessions

    private func appSessionsSection(_ overview: SecurityOverview) -> some View {
        Section {
            ForEach(overview.appSessions) { appSession in
                HStack {
                    VStack(alignment: .leading, spacing: 2) {
                        HStack(spacing: 6) {
                            Text(appSession.deviceLabel)
                                .font(.subheadline.weight(.medium))
                            if appSession.isCurrent {
                                Text("This device")
                                    .font(.caption2)
                                    .padding(.horizontal, 6)
                                    .padding(.vertical, 1)
                                    .background(Capsule().fill(Color.accentColor.opacity(0.15)))
                            }
                        }
                        Text(sessionSubtitle(appSession))
                            .font(.caption)
                            .foregroundStyle(.secondary)
                    }
                    Spacer()
                    Button("Sign Out") {
                        pendingRevoke = appSession
                    }
                    .font(.caption)
                    .accessibilityIdentifier("security_revoke_\(appSession.apiKeyID)")
                }
            }
            if overview.appSessions.count > 1 {
                Button("Sign Out All Devices", role: .destructive) {
                    showRevokeAll = true
                }
                .accessibilityIdentifier("security_revoke_all")
            }
        } header: {
            Text("App Sessions")
        }
    }

    private func sessionSubtitle(_ appSession: AppSessionRow) -> String {
        let created = "Created \(MemberDisplay.dateLabel(appSession.createdTime))"
        guard let lastUsed = appSession.lastUsedTime else { return created }
        return created + " · Last used \(MemberDisplay.dateLabel(lastUsed))"
    }

    // MARK: Passkeys + Vault

    private func passkeyVaultSection(_ overview: SecurityOverview) -> some View {
        Section {
            LabeledContent("Passkeys", value: "\(overview.passkeyCount)")
            LabeledContent("Sealed Vault", value: overview.vaultActive ? "Active" : "Not set up")
            if let web {
                NavigationLink {
                    WebScreen(title: "Security", target: "/profile/security", client: session.client, web: web)
                } label: {
                    Text("Manage on the Website")
                }
                .accessibilityIdentifier("security_manage_web")
            }
        } header: {
            Text("Passkeys & Vault")
        } footer: {
            Text("Passkeys and the encryption vault are managed on the website.")
        }
    }

    private var setupSheetBinding: Binding<Bool> {
        Binding(
            get: { store.setupPhase != .idle },
            set: { if !$0 { Task { await store.cancelSetup() } } }
        )
    }

    private var revokeDialogBinding: Binding<Bool> {
        Binding(get: { pendingRevoke != nil }, set: { if !$0 { pendingRevoke = nil } })
    }

    private var regenerateSheetBinding: Binding<BackupCodesPayload?> {
        Binding(
            get: { showRegenerateSheet.map(BackupCodesPayload.init(codes:)) },
            set: { if $0 == nil { showRegenerateSheet = nil } }
        )
    }
}

private struct BackupCodesPayload: Identifiable {
    let codes: [String]
    var id: String { codes.joined() }
}

/// TOTP enable flow: a native QR (rendered from the `otpauth://` provisioning
/// URI via `CIFilter.qrCodeGenerator` — no SVG handling needed) plus a
/// 6-digit code field. On success shows the one-time backup codes.
private struct TOTPSetupSheet: View {
    @ObservedObject var store: SecurityStore
    @State private var code = ""
    @Environment(\.dismiss) private var dismiss

    var body: some View {
        NavigationStack {
            Group {
                switch store.setupPhase {
                case .awaitingCode(let uri):
                    Form {
                        if let error = store.setupError {
                            Section { Text(error).foregroundStyle(.red) }
                        }
                        Section {
                            HStack {
                                Spacer()
                                if let image = QRCodeRenderer.image(for: uri) {
                                    Image(uiImage: image)
                                        .interpolation(.none)
                                        .resizable()
                                        .frame(width: 200, height: 200)
                                        .accessibilityIdentifier("security_totp_qr")
                                }
                                Spacer()
                            }
                            Text("Scan this code with your authenticator app, then enter the 6-digit code it shows.")
                                .font(.footnote)
                                .foregroundStyle(.secondary)
                        }
                        Section {
                            TextField("6-digit code", text: $code)
                                .keyboardType(.numberPad)
                                .accessibilityIdentifier("security_totp_code")
                        }
                        Section {
                            Button("Confirm") {
                                Task { await store.confirmSetup(code: code) }
                            }
                            .disabled(store.isBusy || code.isEmpty)
                            .accessibilityIdentifier("security_totp_confirm")
                        }
                    }
                case .justEnabled(let codes):
                    BackupCodesSheet(codes: codes, title: "Two-Factor Authentication Enabled") {
                        store.finishSetup()
                        dismiss()
                    }
                case .idle:
                    ProgressView()
                }
            }
            .navigationTitle("Enable Two-Factor Authentication")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") {
                        Task { await store.cancelSetup(); dismiss() }
                    }
                }
            }
        }
    }
}

/// TOTP disable flow: a current 6-digit code or an 8-character backup code.
private struct TOTPDisableSheet: View {
    @ObservedObject var store: SecurityStore
    @Binding var isPresented: Bool
    @State private var totpCode = ""
    @State private var backupCode = ""

    var body: some View {
        NavigationStack {
            Form {
                if let error = store.setupError {
                    Section { Text(error).foregroundStyle(.red) }
                }
                Section {
                    TextField("6-digit code", text: $totpCode)
                        .keyboardType(.numberPad)
                        .accessibilityIdentifier("security_disable_totp_code")
                } header: {
                    Text("Current authenticator code")
                }
                Section {
                    TextField("8-character backup code", text: $backupCode)
                        .autocapitalization(.allCharacters)
                        .accessibilityIdentifier("security_disable_backup_code")
                } header: {
                    Text("Or a backup code")
                }
                Section {
                    Button("Disable Two-Factor Authentication", role: .destructive) {
                        Task {
                            let ok = await store.disable(totpCode: totpCode, backupCode: backupCode)
                            if ok { isPresented = false }
                        }
                    }
                    .disabled(store.isBusy || (totpCode.isEmpty && backupCode.isEmpty))
                    .accessibilityIdentifier("security_disable_confirm")
                }
            }
            .navigationTitle("Disable Two-Factor Authentication")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { isPresented = false }
                }
            }
        }
    }
}

/// A read-once display of backup codes, shown right after they're minted
/// (initial enable or a regeneration).
struct BackupCodesSheet: View {
    let codes: [String]
    let title: String
    var onDone: (() -> Void)?

    var body: some View {
        NavigationStack {
            List {
                Section {
                    Text("Save these codes somewhere safe. Each one can be used once if you lose access to your authenticator app.")
                        .font(.footnote)
                        .foregroundStyle(.secondary)
                }
                Section {
                    ForEach(codes, id: \.self) { code in
                        Text(code)
                            .font(.system(.body, design: .monospaced))
                    }
                }
                .accessibilityIdentifier("security_backup_codes")
            }
            .navigationTitle(title)
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .confirmationAction) {
                    Button("Done") { onDone?() }
                        .accessibilityIdentifier("security_backup_codes_done")
                }
            }
        }
    }
}

/// Renders an `otpauth://` provisioning URI to a QR image natively.
enum QRCodeRenderer {
    static func image(for string: String) -> UIImage? {
        let context = CIContext()
        let filter = CIFilter.qrCodeGenerator()
        filter.message = Data(string.utf8)
        filter.correctionLevel = "M"
        guard let outputImage = filter.outputImage else { return nil }
        let transform = CGAffineTransform(scaleX: 8, y: 8)
        let scaled = outputImage.transformed(by: transform)
        guard let cgImage = context.createCGImage(scaled, from: scaled.extent) else { return nil }
        return UIImage(cgImage: cgImage)
    }
}
