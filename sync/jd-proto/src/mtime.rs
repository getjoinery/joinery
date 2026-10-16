//! A file's modification time, as it travels between devices.
//!
//! Two places carry one, and they have to agree on its spelling: the
//! `modified_time` a plaintext upload declares (which `file_export` hands
//! back), and the `mtime` sealed inside an encrypted file's metadata blob,
//! which the browser reads and writes with `Date.prototype.toISOString`. Both
//! are ISO-8601 UTC, and this client writes the browser's exact form,
//! `2026-07-20T12:34:56.789Z`, in both, so a vault file sealed here and one
//! sealed in a browser read the same (the parity gate's metadata vector is
//! this form).
//!
//! The server answers in its own spelling: the Postgres timestamp it stores,
//! `2026-07-20 12:34:56`, in UTC by contract and with no zone, to the second
//! (`drive_upload_init` keeps whole seconds). So reading takes either
//! separator, any number of fraction digits, and an optional zone.
//!
//! Hand-rolled rather than a date crate: two conversions between a calendar
//! date and a day count are all there is to it, and every build of the client
//! carries what it depends on.

/// Nanoseconds in a second.
const NS: u64 = 1_000_000_000;

/// The furthest ahead of this device's clock a time may be and still be
/// carried. The server refuses one more than fifty years past its own clock
/// (`_drive_parse_modified_time`), and an upload refused for its date would
/// never go up at all; ten years of margin covers a device clock that runs
/// fast. A time past it is not sent, and the content still is.
const AHEAD_LIMIT_NS: u64 = 40 * 365 * 24 * 3600 * NS;

/// The time to declare for a file whose modification time is `mtime_ns`, read
/// on a device whose clock says `now_ns`, in the browser's spelling. `None`
/// for a time not worth carrying: 0, which every filesystem layer here uses
/// for "not known", or one so far ahead the server would refuse it.
pub fn declared(mtime_ns: u64, now_ns: u64) -> Option<String> {
    if mtime_ns == 0 || mtime_ns > now_ns.saturating_add(AHEAD_LIMIT_NS) {
        return None;
    }
    Some(format(mtime_ns))
}

/// `2026-07-20T12:34:56.789Z`: what `toISOString` gives for the same instant.
/// Milliseconds, as JavaScript keeps them; anything finer is dropped.
pub fn format(mtime_ns: u64) -> String {
    let secs = mtime_ns / NS;
    let millis = (mtime_ns % NS) / 1_000_000;
    let days = (secs / 86_400) as i64;
    let of_day = secs % 86_400;
    let (y, m, d) = civil_from_days(days);
    format!(
        "{y:04}-{m:02}-{d:02}T{:02}:{:02}:{:02}.{millis:03}Z",
        of_day / 3600,
        (of_day / 60) % 60,
        of_day % 60,
    )
}

/// Nanoseconds since the Unix epoch for a time in any of the spellings this
/// client meets: `2026-07-20 12:34:56`, `2026-07-20T12:34:56.789Z`,
/// `2026-07-20T12:34:56+02:00`. A time with no zone is UTC, which is what the
/// field is documented to carry. `None` for anything else, or for a time
/// before 1970 -- a date nothing here can give a file.
pub fn parse(text: &str) -> Option<u64> {
    let b = text.trim().as_bytes();
    let num = |from: usize, len: usize| -> Option<i64> {
        let digits = b.get(from..from + len)?;
        if !digits.iter().all(u8::is_ascii_digit) {
            return None;
        }
        Some(digits.iter().fold(0i64, |n, d| n * 10 + i64::from(d - b'0')))
    };
    if b.len() < 19 || b[4] != b'-' || b[7] != b'-' || !matches!(b[10], b'T' | b't' | b' ') || b[13] != b':' || b[16] != b':' {
        return None;
    }
    let (year, month, day) = (num(0, 4)?, num(5, 2)?, num(8, 2)?);
    let (hour, minute, second) = (num(11, 2)?, num(14, 2)?, num(17, 2)?);
    if !(1..=12).contains(&month) || day < 1 || day > days_in_month(year, month) || hour > 23 || minute > 59 || second > 60 {
        return None;
    }
    let mut at = 19;
    let mut nanos: u64 = 0;
    if b.get(at) == Some(&b'.') {
        at += 1;
        let start = at;
        while b.get(at).is_some_and(u8::is_ascii_digit) {
            if at - start < 9 {
                nanos = nanos * 10 + u64::from(b[at] - b'0');
            }
            at += 1;
        }
        if at == start {
            return None;
        }
        for _ in (at - start)..9 {
            nanos *= 10;
        }
    }
    // The zone, as an offset east of UTC in seconds.
    let offset: i64 = match b.get(at) {
        None => 0,
        Some(b'Z' | b'z') if at + 1 == b.len() => 0,
        Some(&sign @ (b'+' | b'-')) => {
            let rest = &b[at + 1..];
            let (h, m) = match rest.len() {
                2 => (num(at + 1, 2)?, 0),
                4 => (num(at + 1, 2)?, num(at + 3, 2)?),
                5 if rest[2] == b':' => (num(at + 1, 2)?, num(at + 4, 2)?),
                _ => return None,
            };
            if h > 23 || m > 59 {
                return None;
            }
            let east = h * 3600 + m * 60;
            if sign == b'+' { east } else { -east }
        }
        _ => return None,
    };
    // A leap second is the last second of its minute, as a filesystem keeps it.
    let second = second.min(59);
    let secs = days_from_civil(year, month, day) * 86_400 + hour * 3600 + minute * 60 + second - offset;
    if secs < 0 {
        return None;
    }
    (secs as u64).checked_mul(NS)?.checked_add(nanos)
}

fn is_leap(year: i64) -> bool {
    (year % 4 == 0 && year % 100 != 0) || year % 400 == 0
}

fn days_in_month(year: i64, month: i64) -> i64 {
    match month {
        2 if is_leap(year) => 29,
        2 => 28,
        4 | 6 | 9 | 11 => 30,
        _ => 31,
    }
}

/// Days since 1970-01-01 for a proleptic Gregorian date (Howard Hinnant's
/// `days_from_civil`).
fn days_from_civil(year: i64, month: i64, day: i64) -> i64 {
    let y = if month <= 2 { year - 1 } else { year };
    let era = (if y >= 0 { y } else { y - 399 }) / 400;
    let yoe = y - era * 400;
    let mp = (month + 9) % 12;
    let doy = (153 * mp + 2) / 5 + day - 1;
    let doe = yoe * 365 + yoe / 4 - yoe / 100 + doy;
    era * 146_097 + doe - 719_468
}

/// The date `days` after 1970-01-01 (`civil_from_days`, the inverse).
fn civil_from_days(days: i64) -> (i64, i64, i64) {
    let z = days + 719_468;
    let era = (if z >= 0 { z } else { z - 146_096 }) / 146_097;
    let doe = z - era * 146_097;
    let yoe = (doe - doe / 1460 + doe / 36_524 - doe / 146_096) / 365;
    let doy = doe - (365 * yoe + yoe / 4 - yoe / 100);
    let mp = (5 * doy + 2) / 153;
    let day = doy - (153 * mp + 2) / 5 + 1;
    let month = if mp < 10 { mp + 3 } else { mp - 9 };
    let year = yoe + era * 400 + i64::from(month <= 2);
    (year, month, day)
}

#[cfg(test)]
mod tests {
    use super::*;

    /// 2026-07-20T12:34:56Z, the instant the parity gate's metadata vector
    /// carries.
    const PARITY_SECS: u64 = 1_784_550_896;

    #[test]
    fn writes_what_the_browser_writes() {
        assert_eq!(format(PARITY_SECS * NS), "2026-07-20T12:34:56.000Z");
        assert_eq!(format(PARITY_SECS * NS + 789_654_321), "2026-07-20T12:34:56.789Z");
        assert_eq!(format(0), "1970-01-01T00:00:00.000Z");
        // The last day of a leap February, and the day after it.
        assert_eq!(format(951_782_400 * NS), "2000-02-29T00:00:00.000Z");
        assert_eq!(format(951_868_800 * NS), "2000-03-01T00:00:00.000Z");
    }

    #[test]
    fn reads_the_servers_spelling_and_the_browsers() {
        let at = PARITY_SECS * NS;
        assert_eq!(parse("2026-07-20 12:34:56"), Some(at), "the Postgres timestamp the export carries");
        assert_eq!(parse("2026-07-20T12:34:56.000Z"), Some(at), "toISOString");
        assert_eq!(parse("2026-07-20T12:34:56Z"), Some(at));
        assert_eq!(parse("2026-07-20 12:34:56.5"), Some(at + 500_000_000), "Postgres trims trailing zeros");
        assert_eq!(parse("2026-07-20 12:34:56.123456"), Some(at + 123_456_000));
        assert_eq!(parse("2026-07-20T12:34:56.1234567891Z"), Some(at + 123_456_789), "past nanoseconds is dropped");
        assert_eq!(parse("2026-07-20T14:34:56+02:00"), Some(at), "an offset east of UTC");
        assert_eq!(parse("2026-07-20T07:34:56-0500"), Some(at));
        assert_eq!(parse("2026-07-20 12:34:56+00"), Some(at), "timestamptz's own spelling");
    }

    #[test]
    fn what_it_writes_it_reads_back() {
        for ns in [1u64, 999_999_999, PARITY_SECS * NS + 1_000_000, 4_102_444_800 * NS] {
            let ms = ns / 1_000_000 * 1_000_000;
            assert_eq!(parse(&format(ns)), Some(ms), "{}", format(ns));
        }
    }

    #[test]
    fn refuses_what_is_not_a_time() {
        for bad in [
            "", "yesterday", "2026-07-20", "2026-13-01 00:00:00", "2026-02-30 00:00:00",
            "2025-02-29 00:00:00", "2026-07-20 24:00:00", "2026-07-20 12:34:56.", "2026-07-20 12:34:56Q",
            "2026-07-20 12:34:56Z trailing", "1969-12-31 23:59:59", "2026-07-20 12:34:56+2",
        ] {
            assert_eq!(parse(bad), None, "{bad:?}");
        }
        assert_eq!(parse("2024-02-29 00:00:00"), Some(1_709_164_800 * NS), "a real leap day");
    }

    #[test]
    fn declares_only_a_time_the_server_would_take() {
        let now = PARITY_SECS * NS;
        assert_eq!(declared(now, now).as_deref(), Some("2026-07-20T12:34:56.000Z"));
        assert_eq!(declared(0, now), None, "0 is not known, not 1970");
        assert!(declared(now + 39 * 365 * 24 * 3600 * NS, now).is_some());
        assert_eq!(declared(now + 41 * 365 * 24 * 3600 * NS, now), None, "the server refuses one fifty years ahead");
    }
}
