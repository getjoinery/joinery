// swift-tools-version: 5.10
// JoineryKit — the shared native core for Joinery platform apps.
// JoineryMailKit — the native mail surface (specs/mobile_native_email.md),
// a layered module any Joinery app adds for native email.
// JoineryCalendarKit — the native personal-calendar surface (month grid,
// agenda, entry editor), same layering pattern as mail.
// JoineryAIChatKit — the native AI chat surface (conversation list + threaded
// chat with the assistant, send-then-poll streaming), same layering as mail.
// Version: 0.4.1 (navigation shell + webviews + native mail + calendar + AI chat + chat attachments)
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
    ]
)
