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


// ---------------------------------------------------------------------------
// specs/post_release_fleet_defects.md B4.5: three defects in one class.
$dir = harness_scratch_dir('static_cache');
@mkdir($dir, 0775, true);
StaticPageCache::setCacheDirForTests($dir);
harness_defer(function () use ($dir) {
    StaticPageCache::setCacheDirForTests(null);
    array_map('unlink', glob($dir . '/*') ?: array());
    @rmdir($dir);
});
$html = '<!DOCTYPE html><html><head></head><body>page</body></html>';

section('a request with an array query parameter is neither cached nor served');

check(StaticPageCache::createCache('/list', ['a' => ['1']], $html) === false,
    'createCache refuses ?a[]=1');
check(glob($dir . '/list*') === array() || glob($dir . '/list*') === false, 'and writes no file');
check(StaticPageCache::markAsNostatic('/list', ['a' => ['1']]) === false,
    'markAsNostatic refuses it too, so no verdict is written down');
check(StaticPageCache::createCache('/list', ['a' => '1'], $html) !== false,
    'the same page with a scalar parameter caches');
check(is_string(StaticPageCache::checkCache('/list', ['a' => '1'])), 'and is served');
check(StaticPageCache::checkCache('/list', ['a' => ['1']]) === 'nostatic',
    'a request with an array parameter is never served from the cache, whatever is in it');
check(StaticPageCache::checkCache('/list', ['a' => ['2']]) === 'nostatic',
    'so ?a[]=1 and ?a[]=2 can never share a file');

section('/_config cannot reach the config entry');

check(StaticPageCache::CONFIG_KEY !== '_config' && strpos(StaticPageCache::CONFIG_KEY, '.') !== false,
    'the config lives under a key with a "." in it, which no URL can produce');
check(StaticPageCache::markAsNostatic('/_config', []) === true, 'a request for /_config records its verdict');
check(StaticPageCache::getCacheStats()['enabled'] === true, 'and the cache is still on');
check(StaticPageCache::createCache('/about', [], $html) !== false && is_string(StaticPageCache::checkCache('/about', [])),
    'pages still cache and serve afterwards');
check(StaticPageCache::checkCache('/_config', []) === 'nostatic', 'the /_config entry is an ordinary page entry');
StaticPageCache::setEnabled(false);
check(StaticPageCache::checkCache('/about', []) === 'nostatic', 'turning the cache off still works');
StaticPageCache::setEnabled(true);

// A legacy index keeps the config under _config; it moves on load, and a
// page entry that happens to sit under _config is left as a page entry.
file_put_contents($dir . '/index.json', json_encode(array(
    '_config' => array('enabled' => false),
    'about' => array('status' => 'cached', 'url' => '/about', 'time' => 1, 'extension' => '.html'),
)));
StaticPageCache::setCacheDirForTests($dir);
check(StaticPageCache::getCacheStats()['enabled'] === false, 'a legacy _config is read as the config (off stays off)');
$migrated = json_decode((string)file_get_contents($dir . '/index.json'), true);
StaticPageCache::setEnabled(false);
$migrated = json_decode((string)file_get_contents($dir . '/index.json'), true);
check(isset($migrated[StaticPageCache::CONFIG_KEY]) && !isset($migrated['_config']),
    'and the next save writes it under the new key', json_encode(array_keys($migrated)));
file_put_contents($dir . '/index.json', json_encode(array(
    StaticPageCache::CONFIG_KEY => array('enabled' => true),
    '_config' => array('status' => 'nostatic', 'url' => '/_config', 'time' => 1),
)));
StaticPageCache::setCacheDirForTests($dir);
check(StaticPageCache::getCacheStats()['enabled'] === true && StaticPageCache::checkCache('/_config', []) === 'nostatic',
    'a page entry under _config is a page entry, and the config is untouched');

section('an unreadable index turns the cache off for the request, once in the log');

if (function_exists('posix_getuid') && posix_getuid() === 0) {
    harness_skip('unreadable index', 'root can read anything');
} else {
    file_put_contents($dir . '/index.json', json_encode(array(StaticPageCache::CONFIG_KEY => array('enabled' => true))));
    chmod($dir . '/index.json', 0000);
    StaticPageCache::setCacheDirForTests($dir);
    $warnings = array();
    set_error_handler(function ($no, $str) use (&$warnings) { $warnings[] = $str; return true; });
    check(StaticPageCache::checkCache('/about', []) === 'nostatic', 'nothing is served');
    check(StaticPageCache::createCache('/about', [], $html) === false, 'nothing is cached');
    check(StaticPageCache::markAsNostatic('/about', []) === false, 'no verdict is recorded');
    restore_error_handler();
    check($warnings === array(), 'and no warning is raised', implode('; ', $warnings));
    chmod($dir . '/index.json', 0664);
    // The index the pool owns was not replaced with the "off" placeholder,
    // which would have turned the cache off for good.
    $kept = json_decode((string)file_get_contents($dir . '/index.json'), true);
    check(($kept[StaticPageCache::CONFIG_KEY]['enabled'] ?? null) === true, 'and the index on disk was not rewritten', json_encode($kept));
    StaticPageCache::setCacheDirForTests($dir);
}

section('saveIndex() leaves a file the web user can read');

StaticPageCache::createCache('/about', [], $html);
$mode = fileperms($dir . '/index.json') & 0777;
check(($mode & 0044) === 0044, 'the index is group- and world-readable', decoct($mode));
$page_mode = fileperms($dir . '/about.html') & 0777;
check(($page_mode & 0044) === 0044, 'and so is a cached page', decoct($page_mode));
if (function_exists('posix_getuid') && posix_getuid() === 0) {
    check(fileowner($dir . '/index.json') === fileowner($dir), 'run as root, the index is given to the cache directory\'s owner');
} else {
    harness_skip('root chowns the index to the cache owner', 'not root');
}

section('a CLI clearAll() deletes files and never rewrites the index');

StaticPageCache::createCache('/about', [], $html);
$before = (string)file_get_contents($dir . '/index.json');
$removed = StaticPageCache::clearAll();
check($removed >= 1 && !file_exists($dir . '/about.html'), 'the cached files are gone', (string)$removed);
check((string)file_get_contents($dir . '/index.json') === $before, 'the index file is byte-for-byte what it was');
check(StaticPageCache::checkCache('/about', []) === false, 'the stale entry drops out on the next request for it');

harness_finish();
