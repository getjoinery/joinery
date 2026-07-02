import SwiftUI

/// Blocking screen for HTTP 426 UpgradeRequired — this build is below the
/// server's minimum for its `client_app`. Nothing in the app is reachable
/// until the user updates (the gate applies to every endpoint, login
/// included).
public struct UpgradeRequiredView: View {
    let config: JoineryConfig
    let message: String

    @Environment(\.openURL) private var openURL

    public init(config: JoineryConfig, message: String) {
        self.config = config
        self.message = message
    }

    public var body: some View {
        VStack(spacing: 20) {
            Spacer()
            Image(systemName: "arrow.up.circle")
                .font(.system(size: 56))
                .foregroundStyle(config.accentColor)
            Text("Update Required")
                .font(.title.bold())
                .accessibilityIdentifier("upgrade_title")
            Text(message.isEmpty
                 ? "This version of \(config.appName) is no longer supported. Please update to continue."
                 : message)
                .multilineTextAlignment(.center)
                .foregroundStyle(.secondary)
                .accessibilityIdentifier("upgrade_message")
            if let storeURL = config.appStoreURL {
                Button {
                    openURL(storeURL)
                } label: {
                    Text("Update in the App Store")
                        .frame(maxWidth: .infinity)
                }
                .buttonStyle(.borderedProminent)
                .accessibilityIdentifier("upgrade_store_button")
            }
            Spacer()
        }
        .padding(24)
    }
}
