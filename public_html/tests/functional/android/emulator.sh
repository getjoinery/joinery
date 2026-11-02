# Sourced by the Android gates (fortress_gate.sh, member_gate.sh).
#
# emulator_boot_install APP_APK TEST_APK: boot the test emulator on the mini if
# none is up, unlock it, and install both APKs.
#
# An emulator that died leaves its AVD's lock files behind and the next start
# refuses ("multiple emulators"), so they go when no emulator is running; the
# wait gives up after five minutes rather than hanging the gate. A cold boot
# leaves the user locked (no app starts, "Activity class does not exist")
# until the screen-lock PIN is entered.
#
# Needs AVD, AVD_PIN and mini() (ssh macmini with ~/.android-env sourced).

emulator_boot_install() {
    local app_apk="$1" test_apk="$2"
    mini "(adb devices | grep -q emulator) || { rm -f ~/.android/avd/$AVD.avd/*.lock; \
          (nohup ~/android-sdk/emulator/emulator @$AVD -no-window -no-audio -no-boot-anim -no-snapshot -gpu swiftshader_indirect >~/emulator.log 2>&1 &); }; \
        for i in \$(seq 1 100); do [ \"\$(adb shell getprop sys.boot_completed 2>/dev/null | tr -d '\r')\" = '1' ] && break; sleep 3; done; \
        [ \"\$(adb shell getprop sys.boot_completed 2>/dev/null | tr -d '\r')\" = '1' ] || { tail -3 ~/emulator.log; exit 1; }; \
        adb shell input keyevent KEYCODE_WAKEUP; \
        if adb shell dumpsys user | grep -q RUNNING_LOCKED; then \
            adb shell wm dismiss-keyguard; sleep 1; adb shell input swipe 540 2000 540 500 300; sleep 2; adb shell input text $AVD_PIN; adb shell input keyevent KEYCODE_ENTER; sleep 3; \
            adb shell dumpsys user | grep -q RUNNING_LOCKED && { echo 'the emulator stays locked: is its PIN $AVD_PIN?'; exit 1; }; \
        fi; \
        adb install -r -t $app_apk >/dev/null && adb install -r -t $test_apk >/dev/null && echo installed"
}
