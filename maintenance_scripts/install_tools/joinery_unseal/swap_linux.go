package main

// Swap that never reaches a disk (specs/unseal_daemon.md § Memory). Locking
// every page is how the daemon keeps a key out of swap; on a host whose swap
// is compressed memory or encrypted under a per-boot key, nothing it holds
// can reach a disk in the clear whether its pages are locked or not. A site
// container cannot raise its memory-lock limit (and an unbounded one there
// would let the container pin the host's memory), so the daemon serves
// unlocked there when, and only while, this holds: it is read at startup and
// every second after, and the daemon drops every window and exits the moment
// it stops holding.
//
// The rule is VaultHealth::checkSwapSafe()'s, the site's own health row:
//   - no active swap device;
//   - zram with no backing device (zram's writeback moves idle pages to one);
//   - device-mapper whose uuid says dm-crypt (CRYPT-).
// Anything else, or anything that cannot be read, is a disk.
//
// A row counts only as a block device (Type partition) that sysfs has. Inside
// a container /proc/swaps lists the host's devices, which the container's /dev
// does not have; sysfs names every block device, so a device is found there by
// its name, and a device-mapper one by its dm/name.
//
// The cases every copy of this rule must agree on are in
// testdata/swap_rule_cases.json, which this package's test, the PHP health
// check's test and the agent's test all walk.

import (
	"fmt"
	"os"
	"path/filepath"
	"strings"
)

// swapRAMOnly reports whether every active swap device keeps its pages off a
// disk, and when not, which device and why. A row is judged as zram or
// dm-crypt only when the kernel lists it as a block device (Type partition)
// and sysfs has that device; a swap file, a name sysfs does not know, or a
// masked or unreadable sysfs is a disk, whatever the name looks like.
func swapRAMOnly(procSwaps, sysBlock string) (bool, string) {
	body, err := os.ReadFile(procSwaps)
	if err != nil {
		return false, fmt.Sprintf("cannot read %s: %v", procSwaps, err)
	}
	lines := strings.Split(strings.TrimSpace(string(body)), "\n")
	for _, line := range lines[1:] { // the first line is the column header
		fields := strings.Fields(line)
		if len(fields) == 0 {
			continue
		}
		path := strings.ReplaceAll(fields[0], `\040`, " ")
		if len(fields) < 2 || fields[1] != "partition" {
			return false, fmt.Sprintf("swap %s is a file, not a block device", path)
		}
		name, resolved := filepath.Base(path), false
		if real, err := filepath.EvalSymlinks(path); err == nil {
			name, resolved = filepath.Base(real), true
		}
		if !isDir(filepath.Join(sysBlock, name)) {
			if resolved {
				// In this /dev but not a whole device in sysfs: a partition
				// (sysfs lists those under their disk), a plain device.
				return false, fmt.Sprintf("swap device %s is a disk", path)
			}
			// In a container the host's /dev/mapper/NAME does not resolve;
			// sysfs still names the dm-N behind it.
			mapped := dmDeviceNamed(sysBlock, filepath.Base(path))
			if mapped == "" {
				return false, fmt.Sprintf("swap device %s is not a block device sysfs shows here", path)
			}
			name = mapped
		}
		switch {
		case strings.HasPrefix(name, "zram"):
			backing, err := os.ReadFile(filepath.Join(sysBlock, name, "backing_dev"))
			if err != nil {
				// The device is known to sysfs: a kernel built without zram
				// writeback has no such file, and no way to write one out.
				if os.IsNotExist(err) {
					continue
				}
				return false, fmt.Sprintf("cannot read %s's backing device: %v", name, err)
			}
			if b := strings.TrimSpace(string(backing)); b != "" && b != "none" {
				return false, fmt.Sprintf("swap device %s writes idle pages back to %s", path, b)
			}
		case strings.HasPrefix(name, "dm-"):
			uuid, err := os.ReadFile(filepath.Join(sysBlock, name, "dm", "uuid"))
			if err != nil || strings.TrimSpace(string(uuid)) == "" {
				return false, fmt.Sprintf("swap device %s is device-mapper of a type that cannot be read", path)
			}
			if !strings.HasPrefix(strings.TrimSpace(string(uuid)), "CRYPT-") {
				return false, fmt.Sprintf("swap device %s is device-mapper but not dm-crypt", path)
			}
		default:
			return false, fmt.Sprintf("swap device %s is a disk", path)
		}
	}
	return true, ""
}

func isDir(p string) bool {
	st, err := os.Stat(p)
	return err == nil && st.IsDir()
}

// dmDeviceNamed is the dm-N whose device-mapper name is mapperName, or "".
func dmDeviceNamed(sysBlock, mapperName string) string {
	dirs, _ := filepath.Glob(filepath.Join(sysBlock, "dm-*"))
	for _, dir := range dirs {
		if b, err := os.ReadFile(filepath.Join(dir, "dm", "name")); err == nil && strings.TrimSpace(string(b)) == mapperName {
			return filepath.Base(dir)
		}
	}
	return ""
}

// exitDiskSwap is the one exit that means "swap can reach a disk": from
// swapcheck, from a start refused for it, and from the guard ending the
// daemon. The launcher records it as a refusal, never as a build that will
// not stay up.
const exitDiskSwap = 3

// runSwapcheck is the installer's question, answered by the rule the daemon
// serves under: exit 0 when swap never reaches a disk, exitDiskSwap with the
// reason when it can, 2 for a bad argument.
func runSwapcheck(args []string) int {
	procSwaps, sysBlock := "/proc/swaps", "/sys/block"
	if len(args) == 2 { // the test suites' fixtures
		procSwaps, sysBlock = args[0], args[1]
	} else if len(args) != 0 {
		fmt.Fprintln(os.Stderr, "usage: joinery-unseal swapcheck [PROC_SWAPS SYS_BLOCK]")
		return 2
	}
	if ok, why := swapRAMOnly(procSwaps, sysBlock); !ok {
		fmt.Println(why)
		return exitDiskSwap
	}
	fmt.Println("swap never reaches a disk")
	return 0
}
