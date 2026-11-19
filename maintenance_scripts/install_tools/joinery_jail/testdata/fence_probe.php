<?php
// Runs INSIDE the fence (jail_fence_test.go). Each line is one row of the
// tables in specs/sandboxed_php_plugins.md design 2, tried the way native code
// would try it: raw system calls through FFI, not PHP's own wrappers, so a
// refusal is the kernel's and never PHP's. Prints "name<TAB>result".
//
// Argument: a JSON object of paths and process ids the Go side set up
// outside the fence (helper processes, sockets, files).

$cfg = json_decode($argv[1], true);
$c = FFI::cdef(<<<'C'
	long syscall(long number, ...);
	int *__errno_location(void);
	char *strerror(int errnum);
C, 'libc.so.6');

function errname(int $e): string {
	static $n = [1 => 'EPERM', 2 => 'ENOENT', 9 => 'EBADF', 13 => 'EACCES', 22 => 'EINVAL', 38 => 'ENOSYS', 95 => 'EOPNOTSUPP', 97 => 'EAFNOSUPPORT', 111 => 'ECONNREFUSED'];
	return $n[$e] ?? "errno$e";
}

function row(string $name, $result): void {
	echo $name, "\t", $result, "\n";
}

// sc() makes one call and answers "reachable" or "refused (ERRNO)".
function sc(string $name, int $nr, ...$args): int {
	global $c;
	$c->__errno_location()[0] = 0;
	$r = $c->syscall($nr, ...array_map('addr', $args));
	if ($r < 0) {
		row($name, 'refused (' . errname($c->__errno_location()[0]) . ')');
	} else {
		row($name, 'reachable');
	}
	return (int)$r;
}

// Raw calls take addresses as integers: a buffer becomes its address, null
// becomes 0.
function addr($a): int {
	global $c;
	if ($a === null) return 0;
	if ($a instanceof FFI\CData) return (int)$c->cast('uintptr_t', FFI::addr($a))->cdata;
	return (int)$a;
}

function buf(string $s) {
	global $c;
	$b = $c->new('char[' . (strlen($s) + 1) . ']', false);
	FFI::memcpy($b, $s . "\0", strlen($s) + 1);
	return $b;
}

$arch = php_uname('m');
$N = $arch === 'aarch64'
	? ['openat' => 56, 'socket' => 198, 'connect' => 203, 'bind' => 200, 'kill' => 129, 'prlimit64' => 261,
	   'inotify_init1' => 26, 'shmget' => 194, 'fchmodat' => 53, 'utimensat' => 88, 'setxattr' => 5,
	   'memfd_create' => 279, 'clone' => 220, 'unshare' => 97, 'prctl' => 167, 'fcntl' => 25,
	   'ptrace' => 117, 'process_vm_readv' => 270, 'pidfd_open' => 434, 'io_uring_setup' => 425,
	   'setpgid' => 154, 'setsid' => 157, 'execve' => 221, 'getdents64' => 61, 'close' => 57]
	: ['openat' => 257, 'socket' => 41, 'connect' => 42, 'bind' => 49, 'kill' => 62, 'prlimit64' => 302,
	   'inotify_init1' => 294, 'shmget' => 29, 'fchmodat' => 268, 'utimensat' => 280, 'setxattr' => 188,
	   'memfd_create' => 319, 'clone' => 56, 'unshare' => 272, 'prctl' => 157, 'fcntl' => 72,
	   'ptrace' => 101, 'process_vm_readv' => 310, 'pidfd_open' => 434, 'io_uring_setup' => 425,
	   'setpgid' => 109, 'setsid' => 112, 'execve' => 59, 'getdents64' => 217, 'close' => 3];
const AT_FDCWD = -100;
const O_RDONLY = 0, O_WRONLY = 1, O_CREAT = 0100, O_DIRECTORY = 0200000;

function open_row(string $name, string $path, int $flags = O_RDONLY): int {
	global $N;
	$fd = sc($name, $N['openat'], AT_FDCWD, buf($path), $flags, 0600);
	return $fd;
}

// ---- files -----------------------------------------------------------------
open_row('read the secret file outside the list', $cfg['secret']);
open_row('read /etc/passwd', '/etc/passwd');
open_row('list /', '/', O_DIRECTORY);
open_row('list /proc', '/proc', O_DIRECTORY);
open_row('read another process command line', '/proc/' . $cfg['helper'] . '/cmdline');
open_row('open another process memory', '/proc/' . $cfg['helper'] . '/mem');
open_row('list another process descriptors', '/proc/' . $cfg['helper'] . '/fd', O_DIRECTORY);
open_row('write /dev/shm', '/dev/shm/jj_probe_' . getmypid(), O_WRONLY | O_CREAT);
open_row('write /tmp', '/tmp/jj_probe_' . getmypid(), O_WRONLY | O_CREAT);
open_row('write the granted read folder', $cfg['readdir'] . '/new.txt', O_WRONLY | O_CREAT);
open_row('read a granted file', $cfg['readdir'] . '/ok.txt');
open_row('read a .php file under a php grant', $cfg['phpdir'] . '/code.php');
open_row('read a key file beside the code', $cfg['phpdir'] . '/key.txt');
open_row('list a php grant folder (names only)', $cfg['phpdir'], O_DIRECTORY);
open_row('read through a link inside a granted folder', $cfg['readdir'] . '/link_to_secret');
if (!empty($cfg['scratch'])) {
	open_row('write the run scratch folder', $cfg['scratch'] . '/staged.bin', O_WRONLY | O_CREAT);
	// D1 (reviewer2): a file the supervisor's sum cannot see.
	open_row('unnamed O_TMPFILE file in the scratch folder', $cfg['scratch'], 020200000 | 02);
	$c->__errno_location()[0] = 0;
	$r = $c->syscall($arch === 'aarch64' ? 35 : 263, AT_FDCWD, addr(buf($cfg['scratch'] . '/staged.bin')), 0);
	row('unlink a scratch file while it is open', $r < 0 ? 'refused (' . errname($c->__errno_location()[0]) . ')' : 'reachable');
}
sc('chmod an owned file outside the list', $N['fchmodat'], AT_FDCWD, buf($cfg['owned']), 0666, 0);
sc('change times of an owned file outside the list', $N['utimensat'], AT_FDCWD, buf($cfg['owned']), null, 0);
sc('set an extended attribute on an owned file', $N['setxattr'], buf($cfg['owned']), buf('user.x'), buf('1'), 1, 0);

// ---- network ----------------------------------------------------------------
sc('TCP socket (database, web server, internet)', $N['socket'], 10, 1, 0);
sc('UDP socket (DNS out)', $N['socket'], 2, 2, 0);
sc('netlink socket', $N['socket'], 16, 3, 0);
sc('Unix socket (database socket file, abstract socket)', $N['socket'], 1, 1, 0);

// ---- other processes ----------------------------------------------------------
sc('signal a process outside the fence', $N['kill'], $cfg['helper'], 18);
sc('signal itself (signal 0)', $N['kill'], getmypid(), 0);
sc('lower another process CPU limit (prlimit)', $N['prlimit64'], $cfg['helper'], 0, $c->new('long[2]', false), null);
sc('read own limits (prlimit pid 0)', $N['prlimit64'], 0, 7, null, $c->new('long[2]', false));
sc('prlimit 2^32 then pid', $N['prlimit64'], (1 << 32) | (int)$cfg['helper'], 7, null, $c->new('long[2]', false));
sc('ptrace another process', $N['ptrace'], 0x4206, $cfg['helper'], 0, 0);
sc('pidfd_open another process', $N['pidfd_open'], $cfg['helper'], 0);
sc('process_vm_readv another process', $N['process_vm_readv'], $cfg['helper'], null, 0, null, 0, 0);

// ---- calls with no Landlock hook ------------------------------------------------
sc('inotify (watch file names and timing)', $N['inotify_init1'], 0);
sc('System V shared memory segment', $N['shmget'], 0x4a505831, 4096, 01000 | 0666);
sc('memfd_create (run a program from memory)', $N['memfd_create'], buf('x'), 0);
sc('io_uring_setup', $N['io_uring_setup'], 1, $c->new('char[120]', false));
sc('user namespace', $N['unshare'], 0x10000000);
sc('setsid', $N['setsid']);
sc('setpgid', $N['setpgid'], 0, 0);
sc('prctl 2^32|PR_SET_PDEATHSIG', $N['prctl'], (1 << 32) | 1, 0, 0, 0, 0);
sc('prctl PR_SET_PDEATHSIG 0', $N['prctl'], 1, 0, 0, 0, 0);
sc('fcntl 2^32|F_SETOWN', $N['fcntl'], 1, (1 << 32) | 8, (int)$cfg['helper']);
sc('fcntl F_SETOWN', $N['fcntl'], 1, 8, (int)$cfg['helper']);
sc('fcntl F_GETFL (allowed)', $N['fcntl'], 1, 3);

// fork: SIGCHLD as the only flag, the way fork(2) calls clone. In a child,
// which must never exist, leave at once.
$r = $c->syscall($N['clone'], 17, 0, 0, 0, 0);
if ($r === 0) { $c->syscall($arch === 'aarch64' ? 94 : 231, 0); }
row('fork', $r < 0 ? 'refused (' . errname($c->__errno_location()[0]) . ')' : 'reachable');

// exec of anything but PHP itself and its loader
$argvb = $c->new('char*[2]', false);
$sh = buf('/bin/sh');
$argvb[0] = $c->cast('char*', FFI::addr($sh[0]));
$argvb[1] = null;
sc('exec /bin/sh', $N['execve'], $sh, $argvb, $argvb);
$r = @proc_open(['/bin/true'], [], $pipes);
row('proc_open from PHP', is_resource($r) ? 'reachable' : 'refused');

// ---- what is accepted ------------------------------------------------------------
row('stat the secret file (accepted: names and sizes)', @stat($cfg['secret']) !== false ? 'reachable' : 'refused');

// ---- PHP still works ----------------------------------------------------------------
$m = fopen('php://temp/maxmemory:1048576', 'w+');
fwrite($m, str_repeat('x', 4096));
rewind($m);
row('php://temp in memory', strlen(fread($m, 4096)) === 4096 ? 'reachable' : 'refused');
date_default_timezone_set('America/New_York');
row('time zones', date('T', 0) === 'EST' ? 'reachable' : 'refused');
row('mbstring and dom', (mb_strtoupper('é') === 'É' && Dom\HTMLDocument::createFromString('<p>x</p>', LIBXML_NOERROR)->body->textContent === 'x') ? 'reachable' : 'refused');
row('done', 'reachable');
