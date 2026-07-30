// swift-tools-version: 5.10
// JoineryKit — the shared native core for Joinery platform apps.
// JoineryMailKit — the native mail surface (specs/mobile_native_email.md),
// a layered module any Joinery app adds for native email.
// JoineryCalendarKit — the native personal-calendar surface (month grid,
// agenda, entry editor), same layering pattern as mail.
// JoineryAIChatKit — the native AI chat surface (conversation list + threaded
// chat with the assistant, send-then-poll streaming), same layering as mail.
// JoineryMemberKit — the native member surface (dashboard, orders,
// subscriptions, events, conversations, security), same layering as mail;
// its Settings additions (address/phone forms, the Security row) live in
// JoineryKit itself and route to this module through NativeScreenRegistry.
// JoineryDNSFilterKit — the native DNS-filtering surface (device policy editor,
// protection-mode control, NEDNSSettingsManager activation, packet-tunnel
// hard-block engine) for any ScrollDaddy-style deployment; same layering as mail.
// JoineryBillingKit — the native in-app purchase surface (StoreKit 2 purchase,
// plan change, restore, server-authoritative status), registered as the
// `billing` screen; same layering as mail.
// Version: 0.7.1 (sender labels match the web reader: organization over local part)
import PackageDescription

let package = Package(
    name: "JoineryKit",
    platforms: [
        .iOS(.v16),
    ],
    products: [
        .library(name: "JoineryKit", targets: ["JoineryKit"]),
        .library(name: "JoineryMailKit", targets: ["JoineryMailKit"]),
        .library(name: "JoineryCalendarKit", targets: ["JoineryCalendarKit"]),
        .library(name: "JoineryAIChatKit", targets: ["JoineryAIChatKit"]),
        .library(name: "JoineryMemberKit", targets: ["JoineryMemberKit"]),
        .library(name: "JoineryDNSFilterKit", targets: ["JoineryDNSFilterKit"]),
        .library(name: "JoineryBillingKit", targets: ["JoineryBillingKit"]),
    ],
    targets: [
        .target(
            name: "JoineryKit",
            path: "Sources/JoineryKit"
        ),
        .target(
            name: "JoineryMailKit",
            dependencies: ["JoineryKit"],
            path: "Sources/JoineryMailKit"
        ),
        .target(
            name: "JoineryCalendarKit",
            dependencies: ["JoineryKit"],
            path: "Sources/JoineryCalendarKit"
        ),
        .target(
            name: "JoineryAIChatKit",
            dependencies: ["JoineryKit"],
            path: "Sources/JoineryAIChatKit"
        ),
        .target(
            name: "JoineryMemberKit",
            dependencies: ["JoineryKit"],
            path: "Sources/JoineryMemberKit"
        ),
        .target(
            name: "JoineryDNSFilterKit",
            dependencies: ["JoineryKit"],
            path: "Sources/JoineryDNSFilterKit"
        ),
        .target(
            name: "JoineryBillingKit",
            dependencies: ["JoineryKit"],
            path: "Sources/JoineryBillingKit"
        ),
        .testTarget(
            name: "JoineryKitTests",
            dependencies: ["JoineryKit"],
            path: "Tests/JoineryKitTests",
            resources: [
                .copy("Fixtures"),
            ]
        ),
        .testTarget(
            name: "JoineryMailKitTests",
            dependencies: ["JoineryMailKit"],
            path: "Tests/JoineryMailKitTests",
            resources: [
                .copy("Fixtures"),
            ]
        ),
        .testTarget(
            name: "JoineryCalendarKitTests",
            dependencies: ["JoineryCalendarKit"],
            path: "Tests/JoineryCalendarKitTests",
            resources: [
                .copy("Fixtures"),
            ]
        ),
        .testTarget(
            name: "JoineryAIChatKitTests",
            dependencies: ["JoineryAIChatKit"],
            path: "Tests/JoineryAIChatKitTests",
            resources: [
                .copy("Fixtures"),
            ]
        ),
        .testTarget(
            name: "JoineryMemberKitTests",
            dependencies: ["JoineryMemberKit"],
            path: "Tests/JoineryMemberKitTests",
            resources: [
                .copy("Fixtures"),
            ]
        ),
        .testTarget(
            name: "JoineryDNSFilterKitTests",
            dependencies: ["JoineryDNSFilterKit"],
            path: "Tests/JoineryDNSFilterKitTests"
        ),
        .testTarget(
            name: "JoineryBillingKitTests",
            dependencies: ["JoineryBillingKit"],
            path: "Tests/JoineryBillingKitTests",
            resources: [
                .copy("Fixtures"),
            ]
        ),
    ]
)
