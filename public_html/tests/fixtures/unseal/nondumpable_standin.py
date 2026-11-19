# A stand-in for a running unseal daemon whose /proc/PID/exe does not resolve,
# as root in a site container sees the real one (no CAP_SYS_PTRACE): it makes
# itself non-dumpable, so even its own user gets EACCES there, while its
# command line stays readable. It records the hold and lift signals it gets.
# Started by tests/vault/unseal_install_test.php as:
#   exec -a STATE/active python3 nondumpable_standin.py SIGNAL_FILE --socket SOCKET
import ctypes, signal, sys, time

ctypes.CDLL(None).prctl(4, 0, 0, 0, 0)  # PR_SET_DUMPABLE 0
out = sys.argv[1]
signal.signal(signal.SIGUSR1, lambda *a: open(out, "a").write("USR1\n"))
signal.signal(signal.SIGUSR2, lambda *a: open(out, "a").write("USR2\n"))
time.sleep(60)
