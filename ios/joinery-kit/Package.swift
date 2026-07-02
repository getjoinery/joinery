// swift-tools-version: 5.10
// JoineryKit — the shared native core for Joinery platform apps.
// Version: 0.1.0 (Phase 2 — auth, forms, settings, upgrade gate)
import PackageDescription

let package = Package(
    name: "JoineryKit",
    platforms: [
        .iOS(.v16),
    ],
    products: [
        .library(name: "JoineryKit", targets: ["JoineryKit"]),
    ],
    targets: [
        .target(
            name: "JoineryKit",
            path: "Sources/JoineryKit"
        ),
        .testTarget(
            name: "JoineryKitTests",
            dependencies: ["JoineryKit"],
            path: "Tests/JoineryKitTests",
            resources: [
                .copy("Fixtures"),
            ]
        ),
    ]
)
