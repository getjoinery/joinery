//! What the rig needs from Windows that the standard library does not give it.
//!
//! On Linux and macOS a daemon is found through its environment (`/proc`,
//! `ps -E`), signalled with `kill`, and measured with `ps` and `lsof`. Windows
//! has none of those. Reading another process's environment there means
//! walking its memory, so a Windows device runs **its own copy of the
//! program** (`Device::program`) and is found by that copy's path, which the
//! system reports for any process. The same copy is what a firewall rule
//! names to cut one device's traffic and leave its neighbour's alone.
//!
//! Signals become their nearest Windows equivalents: `KILL` is
//! `TerminateProcess`, which no process can catch, exactly like `SIGKILL`;
//! `STOP` and `CONT` suspend and resume every thread in the process at once.

use std::os::windows::ffi::OsStringExt;
use std::path::Path;

use windows_sys::Win32::Foundation::{CloseHandle, HANDLE, INVALID_HANDLE_VALUE};
use windows_sys::Win32::System::Diagnostics::ToolHelp::{
    CreateToolhelp32Snapshot, Process32FirstW, Process32NextW, PROCESSENTRY32W, TH32CS_SNAPPROCESS,
};
use windows_sys::Win32::System::ProcessStatus::{K32GetProcessMemoryInfo, PROCESS_MEMORY_COUNTERS};
use windows_sys::Win32::System::Threading::{
    GetProcessHandleCount, OpenProcess, QueryFullProcessImageNameW, TerminateProcess,
    PROCESS_ACCESS_RIGHTS, PROCESS_NAME_WIN32, PROCESS_QUERY_LIMITED_INFORMATION,
    PROCESS_SUSPEND_RESUME, PROCESS_TERMINATE,
};

// Suspending a whole process is what Sysinternals' pssuspend and every
// debugger do, and ntdll is the only place it is offered. The documented
// alternative, suspending each thread from a snapshot, misses any thread the
// process starts between the snapshot and the last suspend, and a daemon whose
// worker thread kept running is not frozen.
#[link(name = "ntdll", kind = "raw-dylib")]
extern "system" {
    fn NtSuspendProcess(process: HANDLE) -> i32;
    fn NtResumeProcess(process: HANDLE) -> i32;
}

/// A process handle, closed when dropped.
struct Process(HANDLE);

impl Process {
    fn open(pid: u32, access: PROCESS_ACCESS_RIGHTS) -> Result<Process, String> {
        // SAFETY: a plain call; a null handle is the failure value.
        let handle = unsafe { OpenProcess(access, 0, pid) };
        if handle.is_null() {
            return Err(format!(
                "cannot open process {pid}: {}",
                std::io::Error::last_os_error()
            ));
        }
        Ok(Process(handle))
    }

    /// The full path of the program this process runs.
    fn image(&self) -> Option<String> {
        let mut buf = vec![0u16; 32_768];
        let mut len = buf.len() as u32;
        // SAFETY: the buffer outlives the call and `len` says how big it is.
        let ok = unsafe { QueryFullProcessImageNameW(self.0, PROCESS_NAME_WIN32, buf.as_mut_ptr(), &mut len) };
        if ok == 0 {
            return None;
        }
        Some(std::ffi::OsString::from_wide(&buf[..len as usize]).to_string_lossy().into_owned())
    }
}

impl Drop for Process {
    fn drop(&mut self) {
        // SAFETY: the handle came from OpenProcess and is closed once.
        unsafe { CloseHandle(self.0) };
    }
}

/// Two spellings of one Windows path: case and slash direction do not matter.
fn same_path(a: &str, b: &str) -> bool {
    let norm = |s: &str| s.replace('/', "\\").to_lowercase();
    norm(a) == norm(b)
}

/// The process running `program`, if one is.
///
/// Narrowed by file name from a process snapshot first, so only the few
/// processes with the right name are opened; then held to the whole path,
/// because every device's copy has the same name.
pub fn running(program: &Path) -> Option<u32> {
    let name = program.file_name()?.to_string_lossy().to_lowercase();
    let wanted = program.to_string_lossy();
    // SAFETY: a plain call; INVALID_HANDLE_VALUE is the failure value.
    let snapshot = unsafe { CreateToolhelp32Snapshot(TH32CS_SNAPPROCESS, 0) };
    if snapshot == INVALID_HANDLE_VALUE {
        return None;
    }
    let mut found = None;
    // SAFETY: the entry is plain old data whose size field the API checks.
    unsafe {
        let mut entry: PROCESSENTRY32W = std::mem::zeroed();
        entry.dwSize = std::mem::size_of::<PROCESSENTRY32W>() as u32;
        let mut more = Process32FirstW(snapshot, &mut entry) != 0;
        while more {
            let len = entry.szExeFile.iter().position(|&c| c == 0).unwrap_or(entry.szExeFile.len());
            let exe = String::from_utf16_lossy(&entry.szExeFile[..len]).to_lowercase();
            if exe == name {
                let pid = entry.th32ProcessID;
                let image = Process::open(pid, PROCESS_QUERY_LIMITED_INFORMATION)
                    .ok()
                    .and_then(|p| p.image());
                if image.is_some_and(|image| same_path(&image, &wanted)) {
                    found = Some(pid);
                    break;
                }
            }
            more = Process32NextW(snapshot, &mut entry) != 0;
        }
        CloseHandle(snapshot);
    }
    found
}

/// `KILL`, `STOP` and `CONT`, the signals the fault agent sends.
pub fn signal(pid: u32, signal: &str) -> Result<(), String> {
    match signal {
        "KILL" => {
            let process = Process::open(pid, PROCESS_TERMINATE)?;
            // SAFETY: a live handle opened with the right to terminate.
            if unsafe { TerminateProcess(process.0, 1) } == 0 {
                return Err(format!(
                    "cannot terminate process {pid}: {}",
                    std::io::Error::last_os_error()
                ));
            }
            Ok(())
        }
        "STOP" | "CONT" => {
            let process = Process::open(pid, PROCESS_SUSPEND_RESUME)?;
            // SAFETY: a live handle opened with the right to suspend and resume.
            let status = unsafe {
                if signal == "STOP" {
                    NtSuspendProcess(process.0)
                } else {
                    NtResumeProcess(process.0)
                }
            };
            if status < 0 {
                return Err(format!("{signal} on process {pid} failed (NTSTATUS {status:#x})"));
            }
            Ok(())
        }
        other => Err(format!("Windows has no equivalent of SIG{other}")),
    }
}

/// The process's working set, in kilobytes, as `/proc` reports resident size.
pub fn working_set_kb(pid: u32) -> Option<u64> {
    let process = Process::open(pid, PROCESS_QUERY_LIMITED_INFORMATION).ok()?;
    // SAFETY: the counters are plain old data and `cb` says how big they are.
    unsafe {
        let mut counters: PROCESS_MEMORY_COUNTERS = std::mem::zeroed();
        counters.cb = std::mem::size_of::<PROCESS_MEMORY_COUNTERS>() as u32;
        if K32GetProcessMemoryInfo(process.0, &mut counters, counters.cb) == 0 {
            return None;
        }
        Some(counters.WorkingSetSize as u64 / 1024)
    }
}

/// How many handles the process holds: Windows' count of open descriptors. It
/// covers more kinds of object than a Unix descriptor table (threads, events,
/// registry keys), so it is read as a trend, never against a Unix figure.
pub fn handle_count(pid: u32) -> Option<u64> {
    let process = Process::open(pid, PROCESS_QUERY_LIMITED_INFORMATION).ok()?;
    let mut count = 0u32;
    // SAFETY: a live handle and a place for the answer.
    if unsafe { GetProcessHandleCount(process.0, &mut count) } == 0 {
        return None;
    }
    Some(count as u64)
}

/// A directory's file index: on NTFS its MFT record and that record's reuse
/// count, so a later directory that takes over a freed record has another
/// index. The same number the engine reads as a directory's identity
/// (jd-vfs), and only ever obtainable from an open handle.
pub fn file_index(path: &Path) -> Option<u64> {
    use std::os::windows::fs::OpenOptionsExt;
    use std::os::windows::io::AsRawHandle;
    use windows_sys::Win32::Storage::FileSystem::{
        GetFileInformationByHandle, BY_HANDLE_FILE_INFORMATION, FILE_FLAG_BACKUP_SEMANTICS,
        FILE_READ_ATTRIBUTES, FILE_SHARE_DELETE, FILE_SHARE_READ, FILE_SHARE_WRITE,
    };

    let file = std::fs::OpenOptions::new()
        .access_mode(FILE_READ_ATTRIBUTES)
        // Every share mode, so reading an identity never stands in the way of
        // the daemon or an actor renaming or deleting the directory.
        .share_mode(FILE_SHARE_READ | FILE_SHARE_WRITE | FILE_SHARE_DELETE)
        // Without this a directory cannot be opened at all.
        .custom_flags(FILE_FLAG_BACKUP_SEMANTICS)
        .open(path)
        .ok()?;
    // SAFETY: the handle is live for the duration of the call, and the struct
    // is plain old data the API fills in.
    unsafe {
        let mut info: BY_HANDLE_FILE_INFORMATION = std::mem::zeroed();
        if GetFileInformationByHandle(file.as_raw_handle() as _, &mut info) == 0 {
            return None;
        }
        Some(((info.nFileIndexHigh as u64) << 32) | info.nFileIndexLow as u64)
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::process::{Command, Stdio};

    /// A process of our own to find, signal and measure: a copy of
    /// `findstr.exe`, named as the daemon is and reading a pipe until it
    /// closes, under a path nothing else runs from.
    fn spare(tag: &str) -> (std::path::PathBuf, std::process::Child) {
        let dir = std::env::temp_dir().join(format!("jd-soak-win-{tag}-{}", std::process::id()));
        let _ = std::fs::remove_dir_all(&dir);
        std::fs::create_dir_all(&dir).unwrap();
        let program = dir.join("joinery-drive.exe");
        let system = std::env::var("SystemRoot").unwrap_or_else(|_| r"C:\Windows".into());
        std::fs::copy(Path::new(&system).join(r"System32\findstr.exe"), &program).unwrap();
        // findstr with no file reads standard input until it closes.
        let child = Command::new(&program)
            .arg("x")
            .stdin(Stdio::piped())
            .stdout(Stdio::null())
            .spawn()
            .unwrap();
        (program, child)
    }

    #[test]
    fn a_device_is_found_by_its_own_copy_of_the_program_and_no_other() {
        let (a, mut child_a) = spare("find-a");
        let (b, mut child_b) = spare("find-b");
        assert_eq!(running(&a), Some(child_a.id()));
        assert_eq!(running(&b), Some(child_b.id()));
        // The same path spelled another way is the same program.
        let shouted = a.to_string_lossy().to_uppercase().replace('\\', "/");
        assert_eq!(running(Path::new(&shouted)), Some(child_a.id()));
        child_a.kill().unwrap();
        child_a.wait().unwrap();
        assert_eq!(running(&a), None, "a program nothing runs finds nothing");
        assert_eq!(running(&b), Some(child_b.id()), "and its neighbour is untouched");
        child_b.kill().unwrap();
        child_b.wait().unwrap();
    }

    #[test]
    fn kill_ends_the_process_the_way_sigkill_does() {
        let (program, mut child) = spare("kill");
        signal(child.id(), "KILL").unwrap();
        let status = child.wait().unwrap();
        assert_eq!(status.code(), Some(1));
        assert_eq!(running(&program), None);
    }

    #[test]
    fn a_frozen_process_cannot_react_until_it_is_resumed() {
        let (program, mut child) = spare("freeze");
        signal(child.id(), "STOP").unwrap();
        // Its input closes, which would end it at once if it could run.
        drop(child.stdin.take());
        std::thread::sleep(std::time::Duration::from_millis(500));
        assert!(child.try_wait().unwrap().is_none(), "a frozen process exited");
        assert_eq!(running(&program), Some(child.id()));
        signal(child.id(), "CONT").unwrap();
        child.wait().unwrap();
        assert_eq!(running(&program), None);
    }

    #[test]
    fn a_signal_windows_has_no_equivalent_for_is_refused_in_words() {
        let err = signal(std::process::id(), "HUP").unwrap_err();
        assert!(err.contains("SIGHUP"), "{err}");
    }

    #[test]
    fn a_running_process_has_a_working_set_and_handles() {
        let me = std::process::id();
        assert!(working_set_kb(me).unwrap() > 0);
        assert!(handle_count(me).unwrap() > 0);
    }

    #[test]
    fn a_directory_keeps_its_index_across_a_rename_and_two_directories_differ() {
        let base = std::env::temp_dir().join(format!("jd-soak-win-index-{}", std::process::id()));
        let _ = std::fs::remove_dir_all(&base);
        std::fs::create_dir_all(base.join("one")).unwrap();
        std::fs::create_dir_all(base.join("two")).unwrap();
        let one = file_index(&base.join("one")).unwrap();
        assert_ne!(Some(one), file_index(&base.join("two")));
        std::fs::rename(base.join("one"), base.join("renamed")).unwrap();
        assert_eq!(file_index(&base.join("renamed")), Some(one));
        let _ = std::fs::remove_dir_all(&base);
    }
}
