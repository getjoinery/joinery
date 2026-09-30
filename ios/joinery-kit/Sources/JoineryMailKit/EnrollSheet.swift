import SwiftUI
import JoineryKit

/// Handing this phone the mail key (specs/fortress_mobile_apps.md § R2, R10).
///
/// The phone asks the server for a link bound to the session key it already
/// signed in with, shows the code and the page to open on a computer where the
/// person's mail is open, and waits. The computer seals the mail key to this
/// phone's device key; the poll collects it and the key is stored behind the
/// biometric lock. Nothing here unlocks against the server.
struct EnrollSheet: View {
    @ObservedObject var vault: DeviceVault
    let onDone: () -> Void

    @State private var ticket: DeviceVault.Ticket?
    @State private var state: String = "Asking your site for a code…"
    @State private var failed = false
    @Environment(\.dismiss) private var dismiss

    var body: some View {
        NavigationStack {
            VStack(spacing: 20) {
                Image(systemName: "lock.shield")
                    .font(.system(size: 44))
                    .foregroundStyle(.tint)
                Text("On a computer where your mail is open, go to the page below and enter this code. Your mail key is sealed to this phone and stays behind your Face ID or Touch ID.")
                    .multilineTextAlignment(.center)
                    .font(.subheadline)
                if let ticket {
                    Text(ticket.linkCode)
                        .font(.system(size: 40, weight: .bold, design: .monospaced))
                        .textSelection(.enabled)
                        .accessibilityIdentifier("mail_enroll_code")
                    if let url = ticket.verifyURL {
                        Text(url.absoluteString.components(separatedBy: "?").first ?? url.absoluteString)
                            .font(.footnote.monospaced())
                            .textSelection(.enabled)
                            .accessibilityIdentifier("mail_enroll_url")
                        ShareLink(item: url) { Label("Send the link to your computer", systemImage: "square.and.arrow.up") }
                    }
                }
                Text(state)
                    .font(.footnote)
                    .foregroundStyle(failed ? .red : .secondary)
                    .multilineTextAlignment(.center)
                    .accessibilityIdentifier("mail_enroll_state")
                if failed {
                    Button("Try again") {
                        Task {
                            // A handover already collected is kept, not collected again.
                            if vault.pendingHandover != nil { await retryKeep() } else { await run() }
                        }
                    }
                        .buttonStyle(.borderedProminent)
                }
                Spacer()
            }
            .padding()
            .navigationTitle("Hand this phone the key")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .topBarLeading) {
                    Button("Cancel") { dismiss() }.accessibilityIdentifier("mail_enroll_cancel")
                }
            }
            .task { await run() }
        }
    }

    private func retryKeep() async {
        failed = false
        state = "Opening this phone's key…"
        do {
            try await vault.retryHandover()
            state = "This phone holds your mail key."
            onDone()
            dismiss()
        } catch {
            failed = true
            state = (error as? LocalizedError)?.errorDescription ?? error.localizedDescription
        }
    }

    private func run() async {
        failed = false
        do {
            state = "Asking your site for a code…"
            let t = try await vault.enroll()
            ticket = t
            state = "Waiting for you to approve this phone on a computer…"
            var after = max(2, t.pollAfter)
            while !Task.isCancelled {
                try await Task.sleep(nanoseconds: UInt64(after) * 1_000_000_000)
                switch try await vault.poll(t) {
                case .pending(let next):
                    after = max(2, next)
                case .approved:
                    state = "This phone holds your mail key."
                    onDone()
                    dismiss()
                    return
                case .denied:
                    failed = true
                    state = "The computer declined this phone."
                    return
                case .expired:
                    failed = true
                    state = "The code expired. Try again."
                    return
                }
            }
        } catch is CancellationError {
        } catch DeviceKeyStore.Failure.cancelled {
            failed = true
            state = "Face ID was cancelled, so the key was not kept. Try again."
        } catch DeviceVault.Failure.wrongKey {
            failed = true
            state = "The key handed over is not your mail vault's key. Nothing was kept."
        } catch {
            failed = true
            state = (error as? JoineryAPIError)?.displayMessage
                ?? (error as? LocalizedError)?.errorDescription ?? error.localizedDescription
        }
    }
}
