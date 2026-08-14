// Headed Firefox with a persistent Facebook profile. Log in by hand, then close
// the window (or Ctrl+C). This is the one step that must be a human: it holds
// an aged, real login the headless reader later reuses.
const { firefox } = require("playwright");
const PROFILE = process.env.HOME + "/persona-browser/profiles/facebook";
(async () => {
  const ctx = await firefox.launchPersistentContext(PROFILE, { headless: false, viewport: null });
  const page = ctx.pages()[0] || await ctx.newPage();
  await page.goto("https://www.facebook.com/", { waitUntil: "domcontentloaded" });
  console.log(">>> Firefox is open. Log into Facebook (clear any new-login / 2FA prompts).");
  console.log(">>> When your feed is showing, close the Firefox window. Profile saved to:", PROFILE);
  ctx.on("close", () => process.exit(0));
  await new Promise(() => {});
})().catch(e => { console.error("LOGIN ERROR:", e.message); process.exit(1); });
