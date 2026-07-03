import SwiftUI

/// Native login. The web login page never appears in the app — this screen
/// is the app's one credential entry point (`POST /api/v1/auth/login`).
public struct LoginView: View {
    @ObservedObject var session: SessionController

    @State private var email = ""
    @State private var password = ""
    @State private var errorMessage: String?
    @State private var busy = false
    @State private var showReset = false
    @State private var showRegister = false

    public init(session: SessionController) {
        self.session = session
    }

    public var body: some View {
        NavigationStack {
            Form {
                Section {
                    VStack(spacing: 8) {
                        Text(session.client.config.appName)
                            .font(.largeTitle.bold())
                        Text("Sign in to continue")
                            .foregroundStyle(.secondary)
                    }
                    .frame(maxWidth: .infinity)
                    .listRowBackground(Color.clear)
                }
                Section {
                    TextField("Email", text: $email)
                        .keyboardType(.emailAddress)
                        .textInputAutocapitalization(.never)
                        .autocorrectionDisabled()
                        .textContentType(.username)
                        .accessibilityIdentifier("login_email")
                    SecureField("Password", text: $password)
                        .textContentType(.password)
                        .accessibilityIdentifier("login_password")
                } footer: {
                    if let errorMessage {
                        Text(errorMessage)
                            .foregroundStyle(.red)
                            .accessibilityIdentifier("login_error")
                    }
                }
                Section {
                    Button {
                        Task { await signIn() }
                    } label: {
                        if busy {
                            ProgressView().frame(maxWidth: .infinity)
                        } else {
                            Text("Sign In").frame(maxWidth: .infinity)
                        }
                    }
                    .disabled(busy || email.isEmpty || password.isEmpty)
                    .accessibilityIdentifier("login_submit")
                }
                Section {
                    Button("Forgot password?") { showReset = true }
                        .accessibilityIdentifier("login_forgot")
                    if session.client.config.registrationEnabled {
                        Button("Create an account") { showRegister = true }
                            .accessibilityIdentifier("login_register")
                    }
                }
            }
            // Cap the form to a readable column on large screens (iPad,
            // Split View) and center it; iPhone widths are below the cap so
            // they stay full-width. The grouped background is extended to fill
            // so the centered form doesn't read as a floating card.
            .scrollContentBackground(.hidden)
            .frame(maxWidth: 520)
            .frame(maxWidth: .infinity)
            .background(Color(.systemGroupedBackground))
            .navigationDestination(isPresented: $showReset) {
                PasswordResetFlow(client: session.client) {
                    showReset = false
                }
            }
            .navigationDestination(isPresented: $showRegister) {
                FormScreen(client: session.client, action: "register", authenticated: false)
                    .navigationTitle("Register")
            }
        }
        .tint(session.client.config.accentColor)
    }

    private func signIn() async {
        errorMessage = nil
        busy = true
        defer { busy = false }
        do {
            try await session.login(
                email: email,
                password: password,
                deviceLabel: UIDevice.current.name
            )
        } catch let error as JoineryAPIError {
            // 426 flips the session state globally; everything else shows here.
            if case .upgradeRequired = error { return }
            errorMessage = error.displayMessage
        } catch {
            errorMessage = "Could not sign in. Please try again."
        }
    }
}
