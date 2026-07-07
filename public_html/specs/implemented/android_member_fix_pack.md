# Android Member Screens — Fix Pack

**Status:** active — ready for implementation
**Follows:** `specs/implemented/android_native_member_screens.md`
**Source:** high-effort adversarial code review of the shipped module (8 confirmed defects, 11 verified findings deduplicated). Every fix below was CONFIRMED against the live source — none are speculative.

## The guarantee this pack restores

The Android member surface behaves like its iOS reference and the other
Android modules: destructive actions confirm, lists never render a stale
response over a fresh one, every screen can be refreshed and backed out of,
security self-service is reachable, and the instrumented gate proves what it
claims without leaking the fixture password.

## Fix inventory

| # | File(s) | Defect | Severity |
|---|---------|--------|----------|
| F1 | `SecurityApi.kt`, iOS `SecurityAPI.swift` | 2FA disable sends `totp_code`; server reads only `confirm_code` — a valid authenticator code can never disable 2FA | Blocker |
| F2 | `ConversationsScreen.kt` | Swipe-to-delete fires the server delete with no confirmation and no undo | Blocker |
| F3 | `MemberStores.kt` | No `loadGeneration` stale-load guard in any member list store (spec mandated it) | Major |
| F4 | all six screens, `MemberStores.kt` | No pull-to-refresh anywhere; no ON_RESUME refresh on Orders/Subscriptions/Events/Security | Major |
| F5 | `ProfileScreen.kt` registrations, core `NativeScreenRegistry.kt`, `NavigationShell.kt` | All six registrations drop `ctx.onExit` — no on-screen back arrow | Major |
| F6 | `SecurityScreen.kt` | No in-app path to web passkey/vault management (iOS keeps "Manage on the Website") | Major |
| F7 | `tests/functional/android/member_gate.sh`, `MemberGateSupport.kt` | Fixture password exposed in ssh/adb/on-device process argv | Major |
| F8 | `MainActivity.kt`, core `NativeScreenRegistry.kt` | Web-fallback gate leg is test-order dependent (registry is process-global, flag only skips registration) | Minor |

Paths: Android module is `android/joinery-android-member/src/main/java/com/getjoinery/memberkit/`,
core app is `android/joinery-android/src/main/java/com/getjoinery/android/`,
iOS reference is `ios/joinery-kit/Sources/JoineryMemberKit/`.

---

## F1 — Disabling 2FA with an authenticator code

**Root cause.** The server's `disable` branch (`public_html/logic/security_logic.php:121`)
reads a **single** field, `confirm_code`, and classifies its shape itself: a
6-digit string is verified as TOTP, an 8-character string as a backup code.
The clients invented a two-key contract that doesn't exist: `SecurityApi.kt:49`
sends the authenticator code as `totp_code` (silently ignored) and only the
backup code as `confirm_code`. iOS `SecurityAPI.swift:55` has the identical bug.
Result: the only working disable path burns a one-time backup code.

**Fix — client side, both platforms.** Send whichever confirmation the user
entered as `confirm_code`:

```kotlin
suspend fun disable(totpCode: String, backupCode: String): Boolean {
    val confirmation = totpCode.ifEmpty { backupCode }
    val extra = ArrayList<Pair<String, JsonValue>>()
    extra.add("action" to JsonValue.Str("disable"))
    if (confirmation.isNotEmpty()) extra.add("confirm_code" to JsonValue.Str(confirmation))
    client.submitAction("security", JsonValue.Obj(extra))
    return !overview().totpEnabled
}
```

Same change in `SecurityAPI.swift` (line 55: the `totp_code` append becomes
`confirm_code`, collapsed to one value). Do **not** touch `confirm_enable` —
the server reads `totp_code` there and the enable flow is gate-verified working.
Do not change the server: `confirm_code` is the contract the web form already
uses.

**iOS follow-through.** The iOS change must at minimum build clean on the mini;
re-run the Security leg of the iOS gate if the emulator/simulator budget allows
(Ollama rule applies).

## F2 — Swipe-to-delete confirms before deleting

**Root cause.** `ConversationsScreen.kt` (`SwipeableConversationRow`,
~line 152): `confirmValueChange` invokes `deleteCallback` directly when the
swipe crosses `EndToStart`. iOS only *reveals* a Delete button on partial
swipe; this module's own thread view confirms with "Delete this conversation?"
(`ConversationThreadView.kt:135`).

**Fix.** Crossing the delete threshold arms a confirmation instead of
deleting: set a `pendingDelete` state (remember the conversation), snap the row
back (`false` return stays), and render an `AlertDialog` — same wording as the
thread view: title "Delete this conversation?", confirm "Delete" (destructive
color) calls `onDelete`, dismiss cancels. Mute (`StartToEnd`) stays immediate —
it is non-destructive and self-inverse. Add a `member_conversation_delete_confirm`
testTag on the dialog's confirm button so the gate can drive it.

## F3 — Stale-load guard in every member list store

**Root cause.** The feature spec mandates copying MailboxStore's store
conventions including the `loadGeneration` stale-load guard; no member store
implements it. `EventsScreen.kt:71` launches `store.select(newStatus)` per
filter tap with no cancellation; a slower older response overwrites a newer
one (wrong list under the checked filter, pagination math against the wrong
`totalCount`). The same overlap exists between reload and in-flight `loadMore`
on every list store.

**Fix.** Copy the reference pattern exactly — `MailboxStore.kt:47` and its
uses at `:87-88`, `:94`, `:98`, `:107-110`:

- `private var loadGeneration = 0` per list store.
- Every entry that resets the list (`initialLoad`, `reload`, `select`)
  increments `loadGeneration` first and captures `val generation = loadGeneration`.
- After **every** suspension point, `if (generation != loadGeneration) return`.
- `loadMore` captures the current generation and applies its page only
  `if (generation == loadGeneration)`.

Apply to every list store in `MemberStores.kt` (orders, subscriptions, events,
conversations, sessions) and fix the ProfileStore docstring while there (see F4).

**Test.** Add a unit test per the module's existing store-test pattern: fake
API where the first `select()` response is delayed past the second; assert the
final rendered list belongs to the second selection.

## F4 — Pull-to-refresh on all six screens, ON_RESUME on the missing four

**Root cause.** iOS puts `.refreshable` on all six screens; every other
Android module ships pull-to-refresh (`MailboxScreen.kt:219`
`rememberPullToRefreshState()` / `:270` `PullToRefreshContainer`); the member
module has none. Only Profile (`ProfileScreen.kt:114`) and Conversations
(`ConversationsScreen.kt:74`) observe ON_RESUME. Once loaded,
Orders/Subscriptions/Events/Security are stale until the user backs out and
re-enters — on Security that means revoked sessions and TOTP changes made
elsewhere never appear.

**Fix.**
- Add pull-to-refresh to all six screens using the exact mail-module pattern
  (material3 `pulltorefresh`). Refresh calls the store's `reload()`
  (generation-guarded per F3) and must not blank the list — keep-last-good
  applies while refreshing.
- Add the ON_RESUME `LifecycleEventObserver` (copy `ProfileScreen.kt:113-114`
  shape, including the "skip while Loading" guard) to Orders, Subscriptions,
  Events, and Security.
- `MemberStores.kt:19` ProfileStore docstring claims "refreshed on pull" —
  after this fix it finally tells the truth; verify wording still matches
  behavior.

## F5 — Back navigation: wire `ctx.onExit` through every registration

**Root cause.** All six builders in `ProfileScreen.kt:60-77`
(`JoineryMember.registerScreens()`) discard `ctx.onExit`, so every screen's
`onBack` stays null and `MemberTopBar` renders no back arrow. Both call sites
construct real exits: `NavigationShell.kt:183/207-209` passes `pop`, and
`SettingsScreen.kt:67` passes a route-reset lambda — currently dead code.
`ProfileScreen` doesn't even accept an `onBack` parameter.

**Fix.**
1. Core contract: make exit optional and honest. In `NativeScreenRegistry.kt`,
   `NativeScreenContext.onExit` becomes `(() -> Unit)?` with default `null`
   (a screen can then *hide* the arrow when there is genuinely no back).
   `NavigationShell.kt:209` drops the `?: {}` collapse and passes
   `onExit = onBack`. The member module is the first consumer of `onExit`;
   no other module reads it (verified by grep), so this is a two-line core
   change with no ripple.
2. `ProfileScreen` gains `onBack: (() -> Unit)? = null` and feeds it to its
   top bar like the other five screens already do.
3. All six registrations wire it: e.g.
   `NativeScreenRegistry.register("security") { ctx -> SecurityScreen(ctx.session.client, ctx.web, onBack = ctx.onExit) }`
   (the `ctx.web` addition is F6).

## F6 — Restore the path to web passkey/vault management

**Root cause.** The replaced App Sessions route was the app's only path to the
web `/profile/security` page. iOS keeps a "Manage on the Website"
NavigationLink (`SecurityScreen.swift:188-194`, accessibility id
`security_manage_web`); the Android port (`SecurityScreen.kt:~205`) replaced it
with a non-clickable sentence, and the SettingsScreen webview fallback is
unreachable while the native module is registered. Android users have no
in-app way to manage passkeys or the Sealed Vault.

**Fix.** `SecurityScreen` gains `web: WebSessionCoordinator?` (registration
passes `ctx.web`). Replace the plain `Text` under "Passkeys & Vault" with a
tappable "Manage on the Website" row that opens `/profile/security` through
the web-session bridge — copy the pattern SubscriptionsScreen uses for its
web change-plan/billing rows, including how it hosts the web content and
returns. Keep the testTag `member_security_manage_web` on the tappable row.
On return from the web page, refresh the overview (passkey count / vault flag
may have changed) — the ON_RESUME observer from F4 covers this if the web page
is a separate activity/screen; otherwise refresh explicitly on dismiss.

## F7 — Keep the fixture password out of process argv

**Root cause.** `member_gate.sh` `run_leg()` (~line 79) interpolates
`$TEST_PASSWORD` into the ssh command as `-e password '...'`. It lands in argv
three times: the local ssh process on the dev box, the adb process on the mini,
and the on-device `am instrument` command. Project rule: secrets travel via
stdin/env/files, never positional args. Instrumentation `-e` args are
*inherently* on-device argv, so the fix must move the secret out of the `-e`
channel entirely.

**Fix.**
1. Gate script: before the legs, stream the creds file to the device with no
   argv exposure —
   ```bash
   ssh macmini 'source ~/.android-env; adb shell "cat > /data/local/tmp/joinery_member_gate.creds"' < "$CREDS_FILE"
   ```
   (content flows over stdin end-to-end). Drop `-e password ...` from
   `run_leg`; `-e email` may stay (not a secret).
2. `MemberGateSupport.kt`: when the `password` instrumentation arg is absent,
   read it from `/data/local/tmp/joinery_member_gate.creds` (same
   `key=value` format the script already parses). Keep the arg as an override
   so a single leg can still be run by hand.
3. Cleanup (in the script's existing exit path, so it runs on failure too):
   `ssh macmini 'source ~/.android-env; adb shell rm -f /data/local/tmp/joinery_member_gate.creds'`.

The transient device file is readable only on the throwaway dev emulator and
is deleted at gate end — strictly better than three process lists.

## F8 — Make the web-fallback flag authoritative, not order-dependent

**Root cause.** `MainActivity.kt:36`: `EXTRA_DISABLE_MEMBER` only *skips*
`JoineryMember.registerScreens()`, but `NativeScreenRegistry` is a
process-global map with no unregister. Any earlier test in the same
instrumentation process leaves the screens registered, so the fallback leg
(`buildWithoutModuleFallsBackToWeb`) either fails spuriously or passes without
proving anything when run via plain `connectedDebugAndroidTest`. Only
member_gate.sh's one-method-per-process invocation actually verifies the
version-safe fallback.

**Fix.** Make the flag mean what it says regardless of process history:
- `NativeScreenRegistry` gains `fun unregister(name: String)` (same
  `synchronized(lock)` discipline as `register`).
- `JoineryMember` gains `unregisterScreens()` — unregisters the same six names
  `registerScreens()` registers, kept adjacent so the lists can't drift.
- `MainActivity`: `if (EXTRA_DISABLE_MEMBER) JoineryMember.unregisterScreens() else JoineryMember.registerScreens()`.

## Docs

- `public_html/docs/mobile_apps.md` — in the joinery-android-member section:
  state the store conventions actually implemented (Phase, keep-last-good,
  `loadGeneration` guard, ON_RESUME + pull-to-refresh), the
  `NativeScreenContext.onExit` nullable contract (screens hide the back arrow
  when it is null), and the gate's on-device creds file. Current-state prose
  only — no migration narration.

## Verification gate

All of the following before this spec moves to implemented/:

1. `:joinery-android-member` unit tests pass, including the new stale-load
   race test (F3).
2. Full module + app + androidTest compile clean.
3. `member_gate.sh` green end-to-end (schedule around Ollama on the mini),
   including: the conversation delete leg updated to drive the confirm dialog
   (F2), a Security leg asserting the manage-web row exists and opens (F6),
   and the fallback leg (F8).
4. F8 proven the hard way: run `MemberScreensTest` as a whole class in ONE
   instrumentation process (no `#method` filter) — the fallback method must
   still pass.
5. F1 proven against the live server: with the fixture account's TOTP enabled,
   disable via a current 6-digit code succeeds (no backup code burned).
   iOS `SecurityAPI.swift` builds clean with the same rename.
6. `grep -rn "totp_code" android/joinery-android-member ios/joinery-kit/Sources/JoineryMemberKit`
   shows only the `confirm_enable` sends.
7. `ps`-audit: while a gate leg runs, `ssh macmini "ps -ef | grep -i instrument"`
   shows no password material in any command line.
