import SwiftUI

/// The native settings surface: who you are (from `auth/session`), the
/// server-driven account forms, security (the App Sessions page as a webview
/// destination), and sign out.
public struct SettingsView: View {
    @ObservedObject var session: SessionController
    let user: UserSummary
    /// When the shell supplies its web coordinator, webview destinations
    /// (App Sessions) render; without it (no webview context) they're hidden.
    let web: WebSessionCoordinator?

    @State private var confirmSignOut = false
    @State private var signingOut = false

    public init(session: SessionController, user: UserSummary, web: WebSessionCoordinator? = nil) {
        self.session = session
        self.user = user
        self.web = web
    }

    public var body: some View {
        List {
            Section {
                VStack(alignment: .leading, spacing: 4) {
                    Text(user.displayName.isEmpty ? user.email : user.displayName)
                        .font(.headline)
                        .accessibilityIdentifier("settings_display_name")
                    Text(user.email)
                        .font(.subheadline)
                        .foregroundStyle(.secondary)
                        .accessibilityIdentifier("settings_email")
                }
            }
            Section("Subscription") {
                LabeledContent("Plan", value: user.tier?.name ?? "Free")
                    .accessibilityIdentifier("settings_tier")
            }
            Section("Account") {
                NavigationLink("Edit Account") {
                    FormScreen(client: session.client, action: "account_edit") { _ in
                        Task { await session.refreshUser() }
                    }
                    .navigationTitle("Edit Account")
                }
                .accessibilityIdentifier("settings_account_edit")
                NavigationLink("Contact Preferences") {
                    FormScreen(client: session.client, action: "contact_preferences")
                        .navigationTitle("Contact Preferences")
                }
                .accessibilityIdentifier("settings_contact_preferences")
                NavigationLink("Change Password") {
                    FormScreen(client: session.client, action: "password_edit")
                        .navigationTitle("Change Password")
                }
                .accessibilityIdentifier("settings_password_edit")
            }
            if let web {
                Section("Security") {
                    NavigationLink("App Sessions") {
                        WebScreen(title: "App Sessions", target: "/profile/security",
                                  client: session.client, web: web)
                    }
                    .accessibilityIdentifier("settings_app_sessions")
                }
            }
            Section {
                Button(role: .destructive) {
                    confirmSignOut = true
                } label: {
                    if signingOut {
                        ProgressView()
                    } else {
                        Text("Sign Out")
                    }
                }
                .disabled(signingOut)
                .accessibilityIdentifier("settings_sign_out")
            }
        }
        .navigationTitle("Settings")
        .confirmationDialog("Sign out of this device?", isPresented: $confirmSignOut, titleVisibility: .visible) {
            Button("Sign Out", role: .destructive) {
                signingOut = true
                Task { await session.logout() }
            }
            .accessibilityIdentifier("settings_sign_out_confirm")
        }
    }
}
