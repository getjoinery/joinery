Joinery Drive soak kit
======================

This runs the Joinery Drive sync client's tests on this computer, by itself,
and sends every result to the Joinery team. Nothing you do on this computer
is synced or read: it works only in C:\soak-win, with test accounts on the
test server (drivetest.getjoinery.com).

Install
-------
1. Unzip this whole folder (right-click the zip, Extract All).
2. Double-click install.cmd inside it, and answer Yes when Windows asks.
3. Read the window: it says Installed, or why it did not install, and
   whether anything was changed (if so, uninstall.cmd takes it away).
4. Delete this zip, and the folder you unzipped it into. They held two test
   passwords; the install keeps its own copies, readable by your account only.

What it does
------------
- First it runs the client's test suite once, which takes about an hour.
- Then it runs a soak: two pretend computers syncing test files to the test
  server while it crashes them, freezes them and cuts their network. One run
  starts every two hours and lasts about 40 minutes.
- Every result goes to the Joinery team automatically. There is nothing to
  read or send.

What it changes on this computer
--------------------------------
- C:\soak-win: the programs, the test files, the results.
- Two Windows accounts, soak-a and soak-b: each pretend computer runs as one,
  so each has its own Recycle Bin and saved passwords, as two real computers
  would. They are administrators, because the test has to crash and freeze
  their programs. They are hidden from the sign-in screen, and nobody knows
  their passwords.
- Scheduled tasks named SoakWin-...
- While a run is cutting a pretend computer's network: a firewall rule that
  applies only to that pretend computer's program, removed when the run is
  done.
- While a run is in progress, the computer is kept awake. Between runs it
  sleeps as usual. Windows Update is left alone. A run cut short by sleep, a
  restart or an update is not counted (what it found is still reported).

Remove it
---------
Double-click C:\soak-win\uninstall.cmd and answer Yes. It removes the tasks,
the two accounts, the firewall rules and C:\soak-win, and sends the last
results first.
