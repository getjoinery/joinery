// Manual check: reopen the SAME persistent profile (headless) and confirm the
// login still holds. Prints the final URL and whether you're still signed in,
// and saves a screenshot to feed.png. Use it to verify a login stuck; the
// service (server.js) is what Joinery actually calls.
const { firefox } = require("playwright");
const PROFILE = process.env.HOME + "/persona-browser/profiles/facebook";
(async () => {
  const ctx = await firefox.launchPersistentContext(PROFILE, { headless: true });
  const page = ctx.pages()[0] || await ctx.newPage();
  await page.goto("https://www.facebook.com/", { waitUntil: "domcontentloaded" });
  await page.waitForTimeout(5000);
  const url = page.url();
  const text = await page.evaluate(() => document.body.innerText.replace(/\n{2,}/g, "\n").trim());
  const loggedOut = /log in|log into facebook|create new account|forgotten password/i.test(text.slice(0, 400));
  console.log("FINAL URL:", url);
  console.log("LOGGED_IN:", !loggedOut);
  await page.screenshot({ path: process.env.HOME + "/persona-browser/feed.png", fullPage: false });
  await ctx.close();
})().catch(e => { console.error("READ ERROR:", e.message); process.exit(1); });
