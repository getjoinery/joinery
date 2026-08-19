<?php
/** @joinery-test
 * name: static_page_cache
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * What the page cache must refuse to remember.
 *
 * The cache keys on the full URL, and it writes down a verdict even for URLs it
 * declines to cache. Both of those are fine for pages, which are requested over
 * and over under the same address. They are ruinous for a URL that is unique by
 * construction: a signed download link carries its own signature and expiry, so
 * no two requests for the same file share an address, nothing can ever hit, and
 * every request leaves an entry nothing will ever read again.
 *
 * The index is rewritten whole on each save. So the cost is not the wasted disk
 * -- it is that each download makes the next one slower, until `json_encode`
 * asks for 27 MB, exceeds the memory limit, and every request that touches the
 * cache answers 500. A soak rig found it as file downloads failing forever:
 * 59,977 cached signed URLs, a 1.6 GB cache directory, and a client retrying a
 * download that could not succeed again.
 */
if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

// A browser-ish agent: the User-Agent rules are not what is under test here,
// and an empty one would short-circuit every check below.
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (X11; Linux x86_64)';

section('a signed download link is never cached');

$signed = ['expires' => '1787078905', 'sig' => str_repeat('a', 64)];

check(
    StaticPageCache::shouldCache('/uploads/shot-004.psd', $signed, 'binary') === false,
    'a signed upload URL is not cacheable'
);
check(
    StaticPageCache::shouldCache('/uploads/notes.txt', $signed, 'plain text') === false,
    'nor is one whose name ends in a cacheable extension'
);
check(
    StaticPageCache::isExcludedPath('/uploads/anything.txt'),
    'the uploads path is excluded outright'
);

section('and it is not written down as uncacheable either');

// The half that actually leaks. Refusing to cache still records a verdict
// against the exact URL unless the URL is ignored, and a URL nothing requests
// twice leaves an entry nothing reads twice.
check(
    StaticPageCache::shouldIgnore('/uploads/shot-004.psd', $signed),
    'a signed upload URL is ignored, so no index entry is minted for it'
);
check(
    StaticPageCache::shouldIgnore('/some/page', ['sig' => 'abc']),
    'a signature anywhere means ignore, not just under uploads'
);
check(
    StaticPageCache::shouldIgnore('/admin/dashboard', []),
    'an excluded path is ignored rather than recorded'
);

section('an ordinary page is still cacheable');

// The guard has to stay narrow: if this fails the cache has been turned off
// rather than corrected.
check(
    StaticPageCache::shouldIgnore('/about', []) === false,
    'a plain public page is not ignored'
);
check(
    StaticPageCache::shouldCache('/about', [], '<!DOCTYPE html><html><body>hi</body></html>') === true,
    'and it still caches'
);

section('the URL extension is not taken as proof of the body');

// A file the user uploaded and called .txt may hold anything. A NUL byte says
// plainly that this is not the text page its name claims.
check(
    StaticPageCache::shouldCache('/downloads/report.txt', [], "PK\x03\x04\x00binary") === false,
    'a body with NUL bytes is not cached however the URL is spelled'
);
check(
    StaticPageCache::shouldCache('/downloads/report.txt', [], "a genuinely textual page") === true,
    'a real text file still is'
);

harness_finish();
