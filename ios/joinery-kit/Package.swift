// swift-tools-version: 5.10
// JoineryKit — the shared native core for Joinery platform apps.
// JoineryMailKit — the native mail surface (specs/mobile_native_email.md),
// a layered module any Joinery app adds for native email.
// Version: 0.2.0 (navigation shell + webviews + native mail module)
import PackageDescription

let package = Package(
    name: "JoineryKit",
    platforms: [
        .iOS(.v16),
    ],
    products: [
        .library(name: "JoineryKit", targets: ["JoineryKit"]),
        .library(name: "JoineryMailKit", targets: ["JoineryMailKit"]),
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
    ]
)
