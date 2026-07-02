import SwiftUI

/// Fully native forgot-password: step 1 requests the reset email
/// (`password_reset_1`), the user copies the reset code from that email, and
/// step 2 (`password_reset_2`, code round-tripped via the form's query
/// context) sets the new password. Both steps are server-driven forms.
struct PasswordResetFlow: View {
    let client: APIClient
    let onDone: () -> Void

    @State private var step: Step = .request

    enum Step {
        case request
        case enterCode
        case newPassword(code: String)
        case done
    }

    var body: some View {
        switch step {
        case .request:
            VStack(spacing: 0) {
                FormScreen(client: client, action: "password_reset_1", authenticated: false) { _ in
                    step = .enterCode
                }
                Button("I already have a reset code") { step = .enterCode }
                    .padding(.vertical, 12)
                    .accessibilityIdentifier("reset_have_code")
            }
            .navigationTitle("Reset Password")
        case .enterCode:
            CodeEntryView { code in
                step = .newPassword(code: code)
            }
            .navigationTitle("Enter Code")
        case .newPassword(let code):
            FormScreen(
                client: client,
                action: "password_reset_2",
                query: [URLQueryItem(name: "act_code", value: code)],
                authenticated: false
            ) { _ in
                step = .done
            }
            .navigationTitle("New Password")
        case .done:
            VStack(spacing: 16) {
                Image(systemName: "checkmark.circle")
                    .font(.system(size: 44))
                    .foregroundStyle(.green)
                Text("Your password has been reset. Sign in with your new password.")
                    .multilineTextAlignment(.center)
                    .accessibilityIdentifier("reset_done")
                Button("Back to Sign In") { onDone() }
                    .buttonStyle(.borderedProminent)
                    .accessibilityIdentifier("reset_back_to_login")
            }
            .padding()
        }
    }
}

/// The reset email links to the website with a one-time code; natively the
/// user pastes that code here to continue.
private struct CodeEntryView: View {
    let onContinue: (String) -> Void
    @State private var code = ""

    var body: some View {
        Form {
            Section {
                Text("We emailed you a reset link. Enter the code from that email (the part after \u{201C}code=\u{201D} in the link, or paste the whole link).")
                    .font(.callout)
                TextField("Reset code", text: $code)
                    .textInputAutocapitalization(.never)
                    .autocorrectionDisabled()
                    .accessibilityIdentifier("reset_code")
            }
            Section {
                Button("Continue") {
                    onContinue(extractCode(from: code))
                }
                .disabled(code.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty)
                .accessibilityIdentifier("reset_code_continue")
            }
        }
    }

    /// Accept either the bare code or a pasted reset URL containing
    /// `act_code=…` / `code=…`.
    private func extractCode(from input: String) -> String {
        let trimmed = input.trimmingCharacters(in: .whitespacesAndNewlines)
        if let components = URLComponents(string: trimmed),
           let item = components.queryItems?.first(where: { $0.name == "act_code" || $0.name == "code" }),
           let value = item.value, !value.isEmpty {
            return value
        }
        return trimmed
    }
}
