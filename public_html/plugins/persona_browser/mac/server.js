// Persona Browser Service
// A tiny tailnet-bound HTTP service that reads a hand-logged-in social feed by
// driving a persistent Playwright/Firefox profile headlessly.
//
// This service does the one job only it can: hold the aged, hand-logged-in
// session (residential IP) and scroll the virtualized feed. It does NOT parse
// Facebook — it ships the raw markup of each post back to Joinery, which owns
// every site-specific reading decision (see FacebookFeedExtractor.php). The
// only Facebook knowledge here is the feed URL, the scroll count, and the
// post-container selector used to decide what markup to snapshot.
//
// Endpoints (all require Authorization: Bearer <token> from config.json except
// /health):
//   GET  /health          -> service + engine + per-persona profile state
//   POST /content         -> { persona } : scroll the feed, return
//                            { loggedIn, needsLogin, url, posts:[<html>], media:{src:file} }
//   GET  /media/<file>    -> bytes of a cached post image (downloaded during a read)
//
// Login is NOT an endpoint: it needs a headed window in the console session, so
// it is performed by ./login.sh (run by a human). This daemon only does the
// automated headless reads.
//
// Only one Firefox may hold a profile at a time, so reads are serialized per
// persona with a simple in-process lock, and the login window must be closed
// before a read.

const http = require('http');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { firefox } = require('playwright');

const CONFIG = JSON.parse(fs.readFileSync(path.join(__dirname, 'config.json'), 'utf8'));
const MEDIA_DIR = path.join(__dirname, 'media');
fs.mkdirSync(MEDIA_DIR, { recursive: true });

// ---- per-persona serialization (profile lock) ------------------------------
const locks = new Map();
async function withPersonaLock(persona, fn) {
  const prev = locks.get(persona) || Promise.resolve();
  let release;
  const next = new Promise(res => { release = res; });
  locks.set(persona, prev.then(() => next));
  await prev;
  try { return await fn(); }
  finally { release(); }
}

// ---- media: download a post image inside the authenticated page context ----
async function downloadImage(page, src) {
  let u;
  try { u = new URL(src); } catch { return null; }
  const key = crypto.createHash('sha1').update(u.pathname).digest('hex');
  const extMatch = u.pathname.match(/\.(jpg|jpeg|png|gif|webp)/i);
  const ext = extMatch ? extMatch[1].toLowerCase() : 'jpg';
  const file = `${key}.${ext}`;
  const full = path.join(MEDIA_DIR, file);
  if (fs.existsSync(full)) return file;
  const b64 = await page.evaluate(async (s) => {
    try {
      const r = await fetch(s);
      if (!r.ok) return null;
      const buf = await r.arrayBuffer();
      const bytes = new Uint8Array(buf);
      let bin = '';
      for (let i = 0; i < bytes.length; i++) bin += String.fromCharCode(bytes[i]);
      return btoa(bin);
    } catch { return null; }
  }, src).catch(() => null);
  if (!b64) return null;
  try { fs.writeFileSync(full, Buffer.from(b64, 'base64')); } catch { return null; }
  return file;
}

// ---- feed capture ----------------------------------------------------------
// Scroll the virtualized feed and collect the raw outerHTML of every post node
// seen across scrolls. Parsing is Joinery's job; we only hand back markup plus
// the images we could pull through the authenticated context.
async function readFeed(persona) {
  const conf = CONFIG.personas[persona];
  if (!conf) throw new Error(`unknown persona: ${persona}`);
  const profileDir = path.join(CONFIG.profilesDir, persona);
  if (!fs.existsSync(profileDir)) {
    return { loggedIn: false, needsLogin: true, reason: 'no profile — run login.sh', posts: [], media: {} };
  }

  const ctx = await firefox.launchPersistentContext(profileDir, { headless: true });
  try {
    const page = ctx.pages()[0] || await ctx.newPage();
    await page.goto(conf.url, { waitUntil: 'domcontentloaded', timeout: 45000 });
    await page.waitForTimeout(3500);

    const url = page.url();
    const hasLoginForm = await page.$('input[name="email"], input[name="pass"]');
    if (/\/login/.test(url) || hasLoginForm) {
      return { loggedIn: false, needsLogin: true, reason: 'session expired — run login.sh', url, posts: [], media: {} };
    }

    // Facebook's feed is virtualized (only a handful of [aria-posinset] posts
    // stay mounted at once), so accumulate across scrolls. For each post node we
    // stamp every <img> with data-nw (its runtime naturalWidth) before reading
    // outerHTML, because that pixel size is a live-DOM property that does not
    // survive serialization — the Joinery extractor needs it to tell real photos
    // from avatars/icons. We also collect the srcs of large images to download.
    const seenHtml = new Set();
    const posts = [];
    const wantedSrcs = new Set();

    for (let step = 0; step < 9; step++) {
      const batch = await page.$$eval('[aria-posinset]', nodes => nodes.map(node => {
        const large = [];
        node.querySelectorAll('img').forEach(img => {
          const nw = img.naturalWidth || 0;
          img.setAttribute('data-nw', String(nw));
          const src = img.getAttribute('src') || '';
          if (nw >= 300 && src.startsWith('http')) large.push(src);
        });
        return { html: node.outerHTML, large };
      }));
      for (const it of batch) {
        // Cheap transport-level dedup of byte-identical captures; the real
        // semantic dedup (same post seen twice) happens on the Joinery side.
        const hash = crypto.createHash('sha1').update(it.html).digest('hex');
        if (seenHtml.has(hash)) continue;
        seenHtml.add(hash);
        posts.push(it.html);
        for (const s of it.large) wantedSrcs.add(s);
      }
      await page.mouse.wheel(0, 2200);
      await page.waitForTimeout(1300);
    }

    // Download each candidate image once, through the authenticated context,
    // returning a src -> cached filename map for the extractor to resolve.
    const media = {};
    for (const src of wantedSrcs) {
      const file = await downloadImage(page, src);
      if (file) media[src] = file;
    }

    return { loggedIn: true, needsLogin: false, url, count: posts.length, posts, media };
  } finally {
    await ctx.close();
  }
}

// ---- http ------------------------------------------------------------------
function send(res, code, obj) {
  const body = JSON.stringify(obj);
  res.writeHead(code, { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(body) });
  res.end(body);
}

function authed(req) {
  const h = req.headers['authorization'] || '';
  const m = h.match(/^Bearer\s+(.+)$/i);
  return m && m[1] === CONFIG.token;
}

function personaState() {
  const out = {};
  for (const id of Object.keys(CONFIG.personas)) {
    out[id] = { profileExists: fs.existsSync(path.join(CONFIG.profilesDir, id)) };
  }
  return out;
}

const server = http.createServer(async (req, res) => {
  const url = new URL(req.url, `http://${CONFIG.bind}`);

  if (req.method === 'GET' && url.pathname === '/health') {
    return send(res, 200, { ok: true, engine: 'firefox', personas: personaState() });
  }

  if (!authed(req)) return send(res, 401, { error: 'unauthorized' });

  // GET /media/<file> — stream a cached post image
  if (req.method === 'GET' && url.pathname.startsWith('/media/')) {
    const file = path.basename(decodeURIComponent(url.pathname.slice('/media/'.length)));
    const full = path.join(MEDIA_DIR, file);
    if (!full.startsWith(MEDIA_DIR) || !fs.existsSync(full)) return send(res, 404, { error: 'not found' });
    const ext = path.extname(full).slice(1).toLowerCase();
    const type = ext === 'png' ? 'image/png' : ext === 'gif' ? 'image/gif' : ext === 'webp' ? 'image/webp' : 'image/jpeg';
    res.writeHead(200, { 'Content-Type': type, 'Content-Length': fs.statSync(full).size });
    fs.createReadStream(full).pipe(res);
    return;
  }

  if (req.method === 'POST' && url.pathname === '/content') {
    let raw = '';
    req.on('data', c => { raw += c; if (raw.length > 1e5) req.destroy(); });
    req.on('end', async () => {
      let body = {};
      try { body = raw ? JSON.parse(raw) : {}; } catch { return send(res, 400, { error: 'bad json' }); }
      const persona = body.persona || 'facebook';
      if (!CONFIG.personas[persona]) return send(res, 400, { error: `unknown persona: ${persona}` });
      try {
        const result = await withPersonaLock(persona, () => readFeed(persona));
        return send(res, 200, result);
      } catch (e) {
        return send(res, 500, { error: 'read failed', detail: String(e.message || e) });
      }
    });
    return;
  }

  return send(res, 404, { error: 'not found' });
});

server.listen(CONFIG.port, CONFIG.bind, () => {
  console.log(`persona-browser listening on ${CONFIG.bind}:${CONFIG.port}`);
});
