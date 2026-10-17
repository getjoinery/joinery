; Joinery Drive for Windows: a per-user installer. Built by build.ps1, which
; passes VERSION, VERSION_NUMERIC, ARCH (x64 | arm64), BINDIR and OUTFILE.
;
; Per-user throughout, so installing needs no administrator: the programs go to
; %LOCALAPPDATA%\Programs\Joinery Drive, the Start menu entry, the logon entry
; and the Apps & Features entry all live under the current user.
;
; At logon the tray starts and brings the sync daemon up with it, with no
; console window (the daemon is a console program; started directly, its
; window's close button would stop sync). That logon entry is the daemon's own
; `autostart on`: one entry, written and removed by one piece of code, and
; switched off and on again from the settings page. The sync
; client is per-user by nature — it syncs one person's Drive into one person's
; home folder — so a machine-wide install would only add a UAC prompt and a
; program every other account on the PC would start.
;
; Uninstalling removes the programs and every entry the installer or the
; daemon made, and leaves the user's synced folder, settings and sync record
; alone: uninstalling is "stop running this", never "delete my documents".

Unicode true
ManifestDPIAware true
RequestExecutionLevel user
SetCompressor /SOLID lzma
; A file that cannot be written stops the installer. Skipping it — NSIS's
; default, and what a silent run silently does — leaves an old daemon beside a
; new tray under a version number that says everything was replaced.
AllowSkipFiles off

!include MUI2.nsh
!include LogicLib.nsh
!include x64.nsh

!define APP_NAME "Joinery Drive"
!define PUBLISHER "Joinery"
!define DAEMON_EXE "joinery-drive.exe"
!define TRAY_EXE "joinery-drive-tray.exe"
!define UNINST_KEY "Software\Microsoft\Windows\CurrentVersion\Uninstall\JoineryDrive"

Name "${APP_NAME}"
OutFile "${OUTFILE}"
InstallDir "$LOCALAPPDATA\Programs\${APP_NAME}"
BrandingText "${APP_NAME} ${VERSION}"

VIProductVersion "${VERSION_NUMERIC}.0"
VIAddVersionKey "ProductName" "${APP_NAME}"
VIAddVersionKey "ProductVersion" "${VERSION}"
VIAddVersionKey "FileVersion" "${VERSION}"
VIAddVersionKey "CompanyName" "${PUBLISHER}"
VIAddVersionKey "FileDescription" "${APP_NAME} ${VERSION} (${ARCH}) setup"
VIAddVersionKey "LegalCopyright" "${PUBLISHER}"

!ifdef SIGN_COMMAND
  ; The uninstaller is written while the installer is compiled, so it has to be
  ; signed here, from inside makensis; build.ps1 signs the installer itself.
  !uninstfinalize '"${SIGN_COMMAND}" "%1"' = 0
!endif

!ifdef ICON
  ; The app icon build.rs draws from sync/packaging/icons/joinery-drive.svg.
  !define MUI_ICON "${ICON}"
  !define MUI_UNICON "${ICON}"
!endif
!define MUI_ABORTWARNING
; The tray, with the daemon started beside it, as at logon: a fresh install is
; then one click from "Set up Joinery Drive…".
!define MUI_FINISHPAGE_RUN "$INSTDIR\${TRAY_EXE}"
!define MUI_FINISHPAGE_RUN_PARAMETERS "--start-daemon"
!define MUI_FINISHPAGE_RUN_TEXT "Start ${APP_NAME}"
!insertmacro MUI_PAGE_WELCOME
!insertmacro MUI_PAGE_INSTFILES
!insertmacro MUI_PAGE_FINISH
!insertmacro MUI_UNPAGE_CONFIRM
!insertmacro MUI_UNPAGE_INSTFILES
!insertmacro MUI_LANGUAGE "English"

Function .onInit
  ; An ARM64 build cannot run on an x64 PC at all. The reverse works, through
  ; Windows' x64 emulation, so the x64 installer is not refused anywhere.
  !if "${ARCH}" == "arm64"
    ${IfNot} ${IsNativeARM64}
      MessageBox MB_ICONSTOP "This installer is for Windows on ARM. Download the x64 installer for this PC."
      Abort
    ${EndIf}
  !endif
FunctionEnd

; Wait until $R9 can be opened for writing. A running program's file cannot
; be, so this means "until the copy running from this file has exited" — this
; file, not any other copy of the program the user may be running. Up to 60
; seconds; sets $R8 to "ok" or "busy".
!macro WAIT_UNTIL_FREE_FUNC un
Function ${un}WaitUntilFree
  StrCpy $R7 0
  ${Do}
    ${IfNot} ${FileExists} "$R9"
      StrCpy $R8 "ok"
      Return
    ${EndIf}
    ClearErrors
    FileOpen $R6 "$R9" a
    ${IfNot} ${Errors}
      FileClose $R6
      StrCpy $R8 "ok"
      Return
    ${EndIf}
    IntOp $R7 $R7 + 1
    ${If} $R7 >= 120
      StrCpy $R8 "busy"
      Return
    ${EndIf}
    Sleep 500
  ${Loop}
FunctionEnd
!macroend
!insertmacro WAIT_UNTIL_FREE_FUNC ""
!insertmacro WAIT_UNTIL_FREE_FUNC "un."

; Is $R9 running right now (its file in use)? One look, no waiting; sets $R8 to
; "1" or "0".
!macro IN_USE_FUNC un
Function ${un}InUse
  StrCpy $R8 "0"
  ${If} ${FileExists} "$R9"
    ClearErrors
    FileOpen $R6 "$R9" a
    ${If} ${Errors}
      StrCpy $R8 "1"
    ${Else}
      FileClose $R6
    ${EndIf}
  ${EndIf}
FunctionEnd
!macroend
!insertmacro IN_USE_FUNC ""
!insertmacro IN_USE_FUNC "un."

; What was running before the installer stopped it, so it can be started again.
Var WasTray
Var WasDaemon

; Start again what StopRunning stopped, after a silent upgrade (an interactive
; one asks on its finish page). The tray brings the daemon with it; a daemon
; that ran without a tray is started on its own, and lets go of the console it
; is given.
!macro RestartRunning
  ${If} $WasTray == "1"
    ${If} $WasDaemon == "1"
      Exec '"$INSTDIR\${TRAY_EXE}" --start-daemon'
    ${Else}
      Exec '"$INSTDIR\${TRAY_EXE}"'
    ${EndIf}
  ${ElseIf} $WasDaemon == "1"
    Exec '"$INSTDIR\${DAEMON_EXE}" daemon'
  ${EndIf}
!macroend

; When the installer stops part way, only the tray it closed comes back. The
; daemon has not exited — that is why the installer is stopping — and its stop
; request cannot be withdrawn: it exits at its next safe point. Starting
; another daemon now would put two on one sync record;
; the tray (asked to start the daemon, which it does only if none answers)
; shows when it has stopped and offers to start it, and the abort message says
; so.
!macro RestartTrayAfterAbort
  ${If} $WasTray == "1"
    ${If} $WasDaemon == "1"
      Exec '"$INSTDIR\${TRAY_EXE}" --start-daemon'
    ${Else}
      Exec '"$INSTDIR\${TRAY_EXE}"'
    ${EndIf}
  ${EndIf}
!macroend

; What a stop that gave up says, installing and uninstalling alike: true for
; Cancel and for a silent run (which shows no window, and exits with code 2).
!define ABORT_TEXT "No files were changed. ${APP_NAME} will stop as soon as it safely can; start it again from its icon's menu."

; Running copies hold their executables open, and Windows will not replace an
; open file. The daemon is asked to stop through its own control channel, so it
; stands down cleanly; the tray holds nothing and is simply closed.
;
; `stop` returns once the request is queued, and the daemon stands down at its
; next safe point: usually at once, but hashing or encrypting a large file, or
; a download whose connection has gone quiet (up to its 60-second silence
; limit), is not interrupted. So the files are waited on, not a fixed time.
; Still in use after a minute: the person can wait again or stop here; a
; silent run stops here. Stopping here replaces or removes no file and brings
; the tray back, and exits with code 2 — but the stop already asked of the
; daemon still lands at that safe point, which ABORT_TEXT says.
!macro StopRunning un
  StrCpy $R9 "$INSTDIR\${TRAY_EXE}"
  Call ${un}InUse
  StrCpy $WasTray $R8
  StrCpy $R9 "$INSTDIR\${DAEMON_EXE}"
  Call ${un}InUse
  StrCpy $WasDaemon $R8
  ${If} ${FileExists} "$INSTDIR\${DAEMON_EXE}"
    nsExec::Exec '"$INSTDIR\${DAEMON_EXE}" stop'
    Pop $0
  ${EndIf}
  ; Only this user's tray. Read at run time: `$%USERNAME%` would be the name of
  ; whoever compiled the installer.
  ExpandEnvStrings $1 "%USERNAME%"
  nsExec::Exec 'taskkill /F /IM "${TRAY_EXE}" /FI "USERNAME eq $1"'
  Pop $0
  ${Do}
    StrCpy $R9 "$INSTDIR\${DAEMON_EXE}"
    Call ${un}WaitUntilFree
    ${If} $R8 == "ok"
      StrCpy $R9 "$INSTDIR\${TRAY_EXE}"
      Call ${un}WaitUntilFree
    ${EndIf}
    ${If} $R8 == "ok"
      ${Break}
    ${EndIf}
    MessageBox MB_RETRYCANCEL|MB_ICONEXCLAMATION "${APP_NAME} is still running ($R9).$\r$\n$\r$\nIt stops as soon as it safely can; checking or encrypting a large file can take a while. Retry to wait for it again, or Cancel to stop here without changing any files." /SD IDCANCEL IDRETRY stop_running_retry
    !insertmacro RestartTrayAfterAbort
    Abort "${ABORT_TEXT}"
    stop_running_retry:
  ${Loop}
!macroend

Section "Install"
  ; An upgrade keeps the user's choice about starting at logon; only a first
  ; install turns it on.
  ReadRegStr $2 HKCU "${UNINST_KEY}" "InstallLocation"
  !insertmacro StopRunning ""

  SetOutPath "$INSTDIR"
  File "${BINDIR}\${DAEMON_EXE}"
  File "${BINDIR}\${TRAY_EXE}"
  WriteUninstaller "$INSTDIR\uninstall.exe"

  CreateShortcut "$SMPROGRAMS\${APP_NAME}.lnk" "$INSTDIR\${TRAY_EXE}" "" "$INSTDIR\${TRAY_EXE}" 0 SW_SHOWNORMAL "" "${APP_NAME}"

  ; Start at logon: the daemon's own switch, which with the tray installed
  ; beside it registers the tray to start and bring the daemon up.
  ${If} $2 == ""
    nsExec::Exec '"$INSTDIR\${DAEMON_EXE}" autostart on'
    Pop $0
  ${EndIf}

  WriteRegStr HKCU "${UNINST_KEY}" "DisplayName" "${APP_NAME}"
  WriteRegStr HKCU "${UNINST_KEY}" "DisplayVersion" "${VERSION}"
  WriteRegStr HKCU "${UNINST_KEY}" "Publisher" "${PUBLISHER}"
  WriteRegStr HKCU "${UNINST_KEY}" "DisplayIcon" "$INSTDIR\${TRAY_EXE}"
  WriteRegStr HKCU "${UNINST_KEY}" "InstallLocation" "$INSTDIR"
  WriteRegStr HKCU "${UNINST_KEY}" "UninstallString" '"$INSTDIR\uninstall.exe"'
  WriteRegStr HKCU "${UNINST_KEY}" "QuietUninstallString" '"$INSTDIR\uninstall.exe" /S'
  WriteRegDWORD HKCU "${UNINST_KEY}" "NoModify" 1
  WriteRegDWORD HKCU "${UNINST_KEY}" "NoRepair" 1
  SectionGetSize 0 $0
  WriteRegDWORD HKCU "${UNINST_KEY}" "EstimatedSize" $0

  ; A silent upgrade puts back what it stopped, now the new copy; an
  ; interactive one offers "Start Joinery Drive" on its finish page.
  ${If} ${Silent}
    !insertmacro RestartRunning
  ${EndIf}
SectionEnd

Section "Uninstall"
  !insertmacro StopRunning "un."

  ; The daemon removes its own logon entry, so the value's name and contents
  ; stay written in one place (autostart.rs) rather than copied here.
  ${If} ${FileExists} "$INSTDIR\${DAEMON_EXE}"
    nsExec::Exec '"$INSTDIR\${DAEMON_EXE}" autostart off'
    Pop $0
  ${EndIf}

  Delete "$SMPROGRAMS\${APP_NAME}.lnk"
  Delete "$INSTDIR\${DAEMON_EXE}"
  Delete "$INSTDIR\${TRAY_EXE}"
  Delete "$INSTDIR\uninstall.exe"
  ; What a self-update leaves until the next start: staging folders named
  ; `.jd-update-<version>` (jd-daemon update/mod.rs STAGING_PREFIX), holding
  ; the copy it replaced. Only those, by name: the folder itself is removed
  ; only once it is empty, never wholesale.
  FindFirst $0 $1 "$INSTDIR\.jd-update-*"
  ${DoWhile} $1 != ""
    RMDir /r "$INSTDIR\$1"
    FindNext $0 $1
  ${Loop}
  FindClose $0
  RMDir "$INSTDIR"

  DeleteRegKey HKCU "${UNINST_KEY}"
SectionEnd
