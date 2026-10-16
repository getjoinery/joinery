//! The content type an encrypted file's metadata names.
//!
//! The server works out a plaintext file's type from its bytes, and cannot for
//! an encrypted one: all it holds is ciphertext. So the type of a vault file is
//! whatever the device that sealed it wrote into its metadata, and the browser
//! reads it from there to choose the file's icon, whether to open its
//! thumbnail, and what type the decrypted download carries.
//!
//! The browser writes `File.type`, which a browser derives from the file's
//! extension, or `application/octet-stream` when it has none for it
//! (`drive-crypto.js`). This is the same answer from the same evidence: the
//! extension, looked up in the mappings browsers carry built in, so a photo
//! saved into a vault on a computer shows in the browser as the photo it is,
//! exactly as one dropped into the browser does.

/// The browser's fallback for a name it has no type for.
pub const UNKNOWN: &str = "application/octet-stream";

/// Extension (lowercase, without the dot) to type.
const BY_EXTENSION: &[(&str, &str)] = &[
    // Images.
    ("apng", "image/apng"),
    ("avif", "image/avif"),
    ("bmp", "image/bmp"),
    ("gif", "image/gif"),
    ("heic", "image/heic"),
    ("heif", "image/heif"),
    ("ico", "image/x-icon"),
    ("jfif", "image/jpeg"),
    ("jpeg", "image/jpeg"),
    ("jpg", "image/jpeg"),
    ("pjp", "image/jpeg"),
    ("pjpeg", "image/jpeg"),
    ("png", "image/png"),
    ("svg", "image/svg+xml"),
    ("svgz", "image/svg+xml"),
    ("tif", "image/tiff"),
    ("tiff", "image/tiff"),
    ("webp", "image/webp"),
    ("xbm", "image/x-xbitmap"),
    // Audio and video.
    ("flac", "audio/flac"),
    ("m4a", "audio/x-m4a"),
    ("mp3", "audio/mpeg"),
    ("oga", "audio/ogg"),
    ("ogg", "audio/ogg"),
    ("opus", "audio/ogg"),
    ("wav", "audio/wav"),
    ("weba", "audio/webm"),
    ("m4v", "video/mp4"),
    ("mov", "video/quicktime"),
    ("mp4", "video/mp4"),
    ("mpeg", "video/mpeg"),
    ("mpg", "video/mpeg"),
    ("ogm", "video/ogg"),
    ("ogv", "video/ogg"),
    ("webm", "video/webm"),
    // Documents.
    ("csv", "text/csv"),
    ("doc", "application/msword"),
    ("docx", "application/vnd.openxmlformats-officedocument.wordprocessingml.document"),
    ("eml", "message/rfc822"),
    ("epub", "application/epub+zip"),
    ("ics", "text/calendar"),
    ("md", "text/markdown"),
    ("odp", "application/vnd.oasis.opendocument.presentation"),
    ("ods", "application/vnd.oasis.opendocument.spreadsheet"),
    ("odt", "application/vnd.oasis.opendocument.text"),
    ("pdf", "application/pdf"),
    ("ppt", "application/vnd.ms-powerpoint"),
    ("pptx", "application/vnd.openxmlformats-officedocument.presentationml.presentation"),
    ("rtf", "application/rtf"),
    ("text", "text/plain"),
    ("txt", "text/plain"),
    ("xls", "application/vnd.ms-excel"),
    ("xlsx", "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"),
    // The web's own formats.
    ("css", "text/css"),
    ("htm", "text/html"),
    ("html", "text/html"),
    ("js", "text/javascript"),
    ("json", "application/json"),
    ("mjs", "text/javascript"),
    ("wasm", "application/wasm"),
    ("xhtml", "application/xhtml+xml"),
    ("xml", "text/xml"),
    // Archives.
    ("7z", "application/x-7z-compressed"),
    ("gz", "application/gzip"),
    ("tar", "application/x-tar"),
    ("tgz", "application/gzip"),
    ("zip", "application/zip"),
];

/// The type a browser would give a file of this name.
pub fn for_name(name: &str) -> &'static str {
    let Some((stem, ext)) = name.rsplit_once('.') else {
        return UNKNOWN;
    };
    // A leading dot names a hidden file, not an extension: `.bashrc` is a
    // file called bashrc, as a browser reads it.
    if stem.is_empty() {
        return UNKNOWN;
    }
    let ext = ext.to_ascii_lowercase();
    BY_EXTENSION
        .iter()
        .find(|(e, _)| *e == ext)
        .map(|(_, t)| *t)
        .unwrap_or(UNKNOWN)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn a_photo_is_a_photo_whatever_case_its_extension_is_in() {
        assert_eq!(for_name("holiday.jpg"), "image/jpeg");
        assert_eq!(for_name("IMG_0001.JPG"), "image/jpeg");
        assert_eq!(for_name("scan.final.PNG"), "image/png", "only the last extension counts");
        assert_eq!(for_name("Report: finäl 😀.xlsx"), "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet");
    }

    #[test]
    fn a_name_with_no_type_falls_back_as_the_browser_does() {
        assert_eq!(for_name("README"), UNKNOWN);
        assert_eq!(for_name(".bashrc"), UNKNOWN, "a hidden file, not an extension");
        assert_eq!(for_name("archive.unknownext"), UNKNOWN);
        assert_eq!(for_name("trailing."), UNKNOWN);
    }

    #[test]
    fn the_table_holds_one_answer_per_extension() {
        let mut seen = std::collections::BTreeSet::new();
        for (ext, kind) in BY_EXTENSION {
            assert!(seen.insert(*ext), "{ext} is listed twice");
            assert_eq!(*ext, ext.to_ascii_lowercase(), "{ext} must be lowercase to match");
            assert!(kind.contains('/'), "{ext}: {kind}");
        }
    }
}
