import Foundation
import BackgroundTasks
import UserNotifications
import UIKit
import JoineryKit

/// New-mail notifications by polling (specs/fortress_mobile_apps.md § R15).
///
/// A push needs the app publisher's APNs key, which a self-hosted deployment
/// does not hold (pushes through a Joinery-run relay are
/// specs/push_notification_relay.md). So the phone checks: a `BGAppRefreshTask`
/// the OS runs when it chooses calls `mailbox/mailboxes` with the stored
/// session key and compares each turned-on mailbox's `newest_unread_id` with
/// the high-water mark it last notified for. Higher is new mail: one
/// notification per mailbox. The first check after sign-in only records marks.
///
/// A Standard mailbox's notification names the newest message's sender and
/// subject; Private and Fortress name the mailbox only — the session key opens
/// no server window, and the mail key is never held in the background.
public enum MailPolling {
    public static let taskIdentifier = "com.getjoinery.mail.poll"
    static let marksKey = "joinery.mailpoll.marks"
    static let enabledKey = "joinery.mailpoll.enabled"
    static let seededKey = "joinery.mailpoll.seeded"

    /// One mailbox's state as a check sees it.
    public struct Box: Equatable {
        public let aliasID: Int
        public let address: String
        public let level: String
        public let newestUnread: Int?
        public let unread: Int
        public let own: Bool
    }

    public struct Decision: Equatable {
        public var notify: [Box] = []
        public var marks: [Int: Int] = [:]
        public var badge = 0
    }

    /// The high-water-mark rule, pure: a first check (or a mailbox not seen
    /// before) records only, a higher id
    /// notifies once, a lower or missing one is silent; the mark only rises.
    public static func decide(_ boxes: [Box], marks: [Int: Int], enabled: Set<Int>?, seeded: Bool) -> Decision {
        var d = Decision(marks: marks)
        for b in boxes {
            let on = enabled?.contains(b.aliasID) ?? b.own
            guard on else { continue }
            d.badge += b.unread
            guard let newest = b.newestUnread else { continue }
            let mark = marks[b.aliasID]
            // A mailbox seen for the first time only records its mark.
            if seeded, let mark, newest > mark { d.notify.append(b) }
            d.marks[b.aliasID] = max(mark ?? 0, newest)
        }
        return d
    }

    // MARK: Stored state

    static var marks: [Int: Int] {
        get {
            let raw = UserDefaults.standard.dictionary(forKey: marksKey) as? [String: Int] ?? [:]
            return Dictionary(uniqueKeysWithValues: raw.compactMap { k, v in Int(k).map { ($0, v) } })
        }
        set {
            UserDefaults.standard.set(Dictionary(uniqueKeysWithValues: newValue.map { (String($0.key), $0.value) }), forKey: marksKey)
        }
    }

    /// The mailboxes the person turned on; nil = the default (every own mailbox).
    public static var enabled: Set<Int>? {
        get { (UserDefaults.standard.array(forKey: enabledKey) as? [Int]).map(Set.init) }
        set { UserDefaults.standard.set(newValue.map(Array.init), forKey: enabledKey) }
    }

    public static func isOn(_ box: Mailbox) -> Bool { enabled?.contains(box.aliasID) ?? box.own }

    /// Turn one mailbox on or off (asks for notification permission the first time).
    @MainActor
    public static func set(_ box: Mailbox, on: Bool, all: [Mailbox]) async {
        var set = enabled ?? Set(all.filter(\.own).map(\.aliasID))
        if on { set.insert(box.aliasID) } else { set.remove(box.aliasID) }
        enabled = set
        if on { _ = try? await UNUserNotificationCenter.current().requestAuthorization(options: [.alert, .badge, .sound]) }
        schedule()
    }

    /// Sign-out and 401: no schedule, no marks, no badge.
    public static func reset() {
        BGTaskScheduler.shared.cancel(taskRequestWithIdentifier: taskIdentifier)
        UserDefaults.standard.removeObject(forKey: marksKey)
        UserDefaults.standard.removeObject(forKey: seededKey)
        UserDefaults.standard.removeObject(forKey: enabledKey)
        Task { @MainActor in UIApplication.shared.applicationIconBadgeNumber = 0 }
    }

    // MARK: The background task

    private static var config: JoineryConfig?
    private static var keychainService = ""

    /// Call from the app's init (before launch finishes). The app's Info.plist
    /// lists `taskIdentifier` under BGTaskSchedulerPermittedIdentifiers and
    /// `fetch` under UIBackgroundModes.
    public static func registerBackgroundTask(config: JoineryConfig, keychainService: String) {
        self.config = config
        self.keychainService = keychainService
        BGTaskScheduler.shared.register(forTaskWithIdentifier: taskIdentifier, using: nil) { task in
            guard let task = task as? BGAppRefreshTask else { return }
            // The check never needs a vault key, and none is held while it runs.
            // (The handler runs on a background queue; the drop is done before
            // the check starts.)
            DispatchQueue.main.sync { MainActor.assumeIsolated { HeldKeys.dropForBackgroundTask() } }
            schedule()
            let work = Task { await check(); task.setTaskCompleted(success: true) }
            task.expirationHandler = { work.cancel() }
        }
    }

    #if DEBUG
    /// The gate's trigger (debug builds only): a check on every return to the
    /// foreground, its notifications shown as banners even in front — the
    /// Simulator cannot fire a BGAppRefreshTask on demand.
    public static func enableForegroundChecksForTesting() {
        UNUserNotificationCenter.current().delegate = ForegroundBanner.shared
        NotificationCenter.default.addObserver(forName: UIApplication.didBecomeActiveNotification, object: nil,
                                               queue: .main) { _ in Task { await check() } }
    }

    final class ForegroundBanner: NSObject, UNUserNotificationCenterDelegate {
        static let shared = ForegroundBanner()
        func userNotificationCenter(_ center: UNUserNotificationCenter, willPresent notification: UNNotification,
                                    withCompletionHandler done: @escaping (UNNotificationPresentationOptions) -> Void) {
            done([.banner, .list])
        }
    }
    #endif

    /// Ask the OS for the next check (at its discretion, 15 minutes at the earliest).
    public static func schedule() {
        let req = BGAppRefreshTaskRequest(identifier: taskIdentifier)
        req.earliestBeginDate = Date(timeIntervalSinceNow: 15 * 60)
        try? BGTaskScheduler.shared.submit(req)
    }

    /// The message `newest_unread_id` names (a Standard mailbox only), in at
    /// most two calls inside the background budget (R8): the first unread
    /// thread whose latest message is at or past that id holds it. Nil (the
    /// notification then names the mailbox only) when it is not there.
    static func newestUnread(api: MailAPI, box: Box) async -> MailMessage? {
        guard let newest = box.newestUnread,
              let page = try? await api.threadList(aliasID: box.aliasID, view: .inbox, query: "", page: 1),
              let t = threadHolding(newest, in: page.threads),
              let thread = try? await api.thread(key: t.threadKey, aliasID: box.aliasID) else { return nil }
        return thread.messages.first { $0.id == newest }
    }

    static func threadHolding(_ newest: Int, in threads: [ThreadSummary]) -> ThreadSummary? {
        threads.first { $0.hasUnread && $0.latestID >= newest }
    }

    /// One check, with the stored session key. Internal for the gate's trigger.
    @discardableResult
    static func check() async -> Decision? {
        guard let config, let creds = KeychainStore(service: keychainService).loadCredentials() else { return nil }
        let client = APIClient(config: config)
        client.setCredentials(creds)
        let api = MailAPI(client: client)
        guard let home = try? await api.mailboxes() else { return nil }
        let boxes = home.mailboxes.map { Box(aliasID: $0.aliasID, address: $0.address, level: $0.securityLevel,
                                             newestUnread: $0.newestUnreadID, unread: $0.unread, own: $0.own) }
        let seeded = UserDefaults.standard.bool(forKey: seededKey)
        let d = decide(boxes, marks: marks, enabled: enabled, seeded: seeded)
        marks = d.marks
        UserDefaults.standard.set(true, forKey: seededKey)
        for b in d.notify {
            let content = UNMutableNotificationContent()
            content.title = "New message in \(b.address)"
            content.threadIdentifier = "mailbox-\(b.aliasID)"
            content.userInfo = ["alias_id": b.aliasID]
            content.sound = .default
            if b.level == "standard", let m = await newestUnread(api: api, box: b) {
                content.subtitle = MailDisplay.senderName(m.sender)
                content.body = m.subject.isEmpty ? "(no subject)" : m.subject
            }
            try? await UNUserNotificationCenter.current().add(
                UNNotificationRequest(identifier: "mail-\(b.aliasID)-\(b.newestUnread ?? 0)", content: content, trigger: nil))
        }
        await MainActor.run { UIApplication.shared.applicationIconBadgeNumber = d.badge }
        return d
    }
}
