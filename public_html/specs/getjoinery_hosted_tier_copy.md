# getjoinery.com copy: two choices

**Status:** §0b 2026-09-08: the automatic install is WITHDRAWN from the site (script written and rehearsed; runs on the node). §7 and §8 SHIPPED 2026-09-07 (the subscription promise is one Managed will not break). The Managed copy in
§1–§6 is still unwritten and does not go up until Managed can be bought.
**Companion:** `hosted_trial_provisioning.md` (the Managed product, with the
2026-09-06 decisions block at its top),
`content_packs/getjoinery/apply_hosted_product.php` (the product row, 2.1),
`content_packs/getjoinery/apply_install_product.php` (the free install, 1.0),
`content_packs/getjoinery/apply_free_install_update.php` (the site copy, 1.1),
`content_packs/getjoinery/apply_subscription_commitment.php` (the promise, 1.0).

---

## 0b. Amendment, 2026-09-08: the automatic install is withdrawn

**The owner withdrew the automatic install from the site.** In the owner's
words: "I actually don't think the automatic install is better than the
stackscript install. Let's remove it from getjoinery for now and we'll work on
managed for release later." Both routes end with the same server in the buyer's
own Linode account; the StackScript asks the same questions on Linode's deploy
form and needs no order, no cloud grant and no plane in the middle.

So the install ladder is **two rungs**: deploy on Linode (the StackScript,
featured, with `/page/quickstart` as its walkthrough) and self-install. The
Automatic Install product (#9, `customer_cloud`, ref 0) is made INACTIVE, never
deleted. The provisioning code stays: Managed is built on the same path and is
still not on the site.

Applied by `content_packs/getjoinery/apply_remove_automatic_install.php` 1.0
(one transaction; drift reported, never overwritten; lists any customer-cloud
provision not yet finished), with `getjoinery_pages.php` 2.7 carrying the same
words. Rehearsed on the test database: 52 fields on 18 blocks across eight
pages, second run all current, a hand-edited field left alone. §0 below is now
history; §7's free install is gone from the site.

## 0. Amendment, 2026-09-07: the install is free, and it stays

**The owner reversed the retirement.** The $39.99 automatic install is not
withdrawn — the *fee* is. In the owner's words: "the install is just the same
install as the other two options, just through the website form."

So the install ladder is three free rungs, not two choices and a dead one:
self-install, Linode StackScript, and the automatic install we run on the
buyer's own cloud account. Managed remains the only thing on the hosting axis
that costs money, and it is not on the site yet.

Everything in §1–§6 below that says the $39.99 product is "retired", or plans
a two-column table, or writes the phrase *"Free to run yourself, or $12.99 a
month for us to run it"*, is **superseded for now**. It is the plan for the
day Managed goes on sale; it is not what shipped. What shipped is §7.

## 1. The offering, in one breath

getjoinery sells two things:

- **Self-hosted.** Free. Your machine or your cloud account, your accounts
  for mail and storage. The Linode StackScript is the easy way in and is
  presented as a *method* under this choice, never as a tier of its own.
- **Managed.** $12.99 a month, billed from the day you sign up. We host it,
  send your mail (1,000 a month), keep your backups (10 GB), and rebuild
  your site from those backups if it is ever lost.

Nothing else is for sale on the hosting axis. The "we install it on your own
cloud" product is not retired — as of 2026-09-07 it is free, and it is the
first rung of Self-hosted rather than a tier (see §0). The business licences
are a different axis (who you are, not how it runs) and stay where they are.

Why two and not three: a person either will open a cloud account and a mail
account — and then the free StackScript install is easy — or will not, and
then an automatic install on *their* cloud helps nobody. The middle rung sat
on that line and served neither side. **Amended 2026-09-07:** that reasoning
was about the *fee*, not the install. Free, the middle rung costs nobody
anything and is the easiest way in, so it stays and is highlighted.

## 2. Decisions taken

- **D1. Names:** *Self-hosted* and *Managed*. The distinction is the name.
- **D2. One page.** `/page/install` carries both, side by side. No split.
- **D3. The price sentence:** *"$12.99 a month, from the day you sign up."*
  Never "free trial" — there is none — and never a setup fee, because there
  is none. One number.
- **D4. Disaster recovery wording:** *"If your site is ever lost, we rebuild
  it from its backups."* Not "automatic": the backups are sealed to a
  recovery key only the customer holds, and they approve the rebuild with it.
  "Automatic disaster recovery" is held until it exists.
- **D5. Allowances are stated as numbers** — 1,000 emails a month, 10 GB of
  backups — and outgrowing one is described as moving that service to the
  customer's own account. Never "generous", never a bigger plan.

## 3. The pages, and what changes

Thirteen mentions of `$39.99` across seven pages, every one of them now
describing something that is not on sale. Three tiers of work:

### 3.1 `/page/install` — the real work

- **The price card becomes two cards.** *Self-hosted* — free, with the
  StackScript and the manual install as the two routes under it. *Managed* —
  $12.99 a month, what it includes, what happens if you stop paying.
- **The comparison table goes to two columns**, self-hosted and managed. The
  Linode StackScript row folds into self-hosted. The rows that actually
  differ: who owns the machine, who pays the provider, whose mail and storage
  accounts, who fixes it at 2am, what happens if you stop paying.
- **The subheading** (line 512) becomes: *Free to run yourself, or $12.99 a
  month for us to run it.*
- **The note** (line 650: "The $39.99 does not buy a better Joinery") is
  rewritten to say the same thing of Managed: it is the same software, on
  our account instead of yours.
- **The FAQ gains one question:** *what happens if I stop paying?* — the site
  is shut down after 30 days, never deleted by us, and the backups are kept
  90 days from the missed payment.
- **The FAQ answer at line 695** drops the "$39.99 if you want us to do the
  install" clause.

### 3.2 `/page/pricing` and `/page/home` — the tier table

`tier1..tier3` (lines 218–227 and 746–755) become two tiers: *Self-hosted —
Free* and *Managed — $12.99 a month*. The StackScript is mentioned inside the
self-hosted tier's line, not as a tier.

### 3.3 The one-line subheadings — five places

Lines 268, 1041, 1110, 1453, 1705, plus two button labels (706, 1331). One
phrase, used everywhere:

> Free to run yourself, or $12.99 a month for us to run it.

Buttons say **"See the install options"**, never a price.

## 4. What must stay true

- **Every claim names shipped capability.** Managed hosting exists in code
  and is unverified live; the page does not go up until the live gate in
  `hosted_trial_provisioning.md` §11.7 has run. The allowances are the
  numbers in that spec's §12, or nothing.
- **The honest limitation is stated, not buried.** Managed means your data
  is on our machine. Say it in the FAQ the way the site already says Joinery
  has no IMAP. The backups are the exception and the page can say so: sealed
  to your key, unreadable by us.
- **Self-hosted comes first on every page.** Managed is the far end of the
  same spectrum, not the top of it.

## 5. Open

- **A1. The leaving path.** A Managed customer who leaves takes their backup
  shelf and their recovery key and restores onto a self-install. The restore
  is documented; a download of the shelf for a departing customer is not
  built. The FAQ cannot promise "take everything with you" until it is.
- **Does the Managed card go live before the accounts exist?** The product is
  inactive until the operator's cloud token and mail key are set. Either the
  copy ships after that, or the card carries a waitlist rather than a buy
  button. Owner's call; it decides the schedule, not the words.

## 6. How it ships

Same procedure as the small-business page:

1. Edit `content_packs/getjoinery/getjoinery_pages.php` (currently 2.2).
2. Write a self-contained `apply_*.php` beside it, page definitions embedded,
   one transaction, idempotent, `--check` first.
3. Owner copies it to the getjoinery node and runs `--check`, then applies.
4. On the node's store: run `apply_hosted_product.php` (renames the product,
   sets $12.99, retires the setup line). Leave the install product on sale.

`apply_pages_update.php` has not yet been run on the node as of this draft, so
check what is actually live before assuming a block exists to edit.

---

## 7. Shipped 2026-09-07 — the free install

### 7.1 The product

`apply_install_product.php` (1.0) sets every active version of the
customer-cloud install to `0.00` / `single` / no trial and rewrites its three
copy fields. It refuses to create the product, refuses fulfilment reference 1
(that is Managed), and refuses a product with no active version.

**A $0 order never reaches Stripe.** `logic/checkout_logic.php` skips the
payment section when the cart total is zero and posts straight to
`/cart_charge`; `cart_charge_logic` stamps the order `PAID` with
`ord_payment_method = 'free'` and runs fulfilment exactly as for a paid order.
So the product needs no Stripe id, and the provision row is still created.

### 7.2 The copy — 17 mentions, 8 pages

`getjoinery_pages.php` 2.3; pushed by `apply_free_install_update.php` (1.0),
which is field-by-field rather than a page re-seed: every field carries the
exact text it is expected to hold, so a block edited on the node since the
pack was written is **reported and left alone**, never overwritten.

- **home, pricing** — the three-way teaser stops being a price table. All
  three rungs are free, so the columns compare *time*: five minutes, fifteen
  minutes, an hour. The automatic install moves to first position and is the
  highlighted one, because it is now both the easiest and the cheapest.
- **install** — the ladder's price badge, the comparison header, the
  "buys your afternoon back" note (which had lost its premise entirely), the
  FAQ, and both call-to-action buttons. The ladder gains one sentence: it is
  the same install as the two below, run from a form instead of a keyboard.
- **why** — "How we make money" no longer lists the install. It lists
  business licences, the two paid plugins and hosting referral fees.
- **apps, leave-gmail, families, small-business** — the sentences that quoted
  the fee.

**Managed is deliberately not mentioned anywhere on the site.** It cannot be
bought until the operator's cloud token and SMTP2GO key are set, and copy that
sells an inactive product is worse than copy that is a little out of date.
That is the answer to §5's second open question, *for now*: neither a card nor
a waitlist. When Managed goes live, §1–§6 is the plan, and /why's money
paragraph is the first place it belongs.

### 7.3 A defect fixed with it

Four install buttons pointed at `/profile/server_manager/connect_cloud`. That
page only *displays* a provision — the row is created by the order's
fulfilment — so a visitor sent straight there had nothing to connect for. They
now point at the product page, and `apply_free_install_update.php` resolves the
product's real link on the node rather than trusting the pack's guess.

### 7.4 Applied, and what the live site shows

Applied to getjoinery.com 2026-09-07: 1 page block, 43 copy fields (5 already
current), and product #9 to `$0.00` / `single`. Re-checks report everything
current. Against the running site, `$39.99` appears **0 times** on `/`,
`/page/install`, `/page/pricing`, `/page/families`, `/page/why`, `/page/apps`,
`/page/leave-gmail` and `/page/small-business`, the install ladder shows three
*Free* badges, and every new sentence renders.

### 7.5 What the apply taught us

**A block edited through the admin editor comes back CRLF.** Version 1.0 of the
script compared `html` fields whole and reported four blocks as hand-edited when
only their line endings had changed. Version 1.1 matches `html` by fragment,
giving the search string the field's own line endings — so the surrounding
markup is left byte-for-byte alone. Short fields are still compared whole, but
against a *list* of accepted previous values, because the node's wording moves
on from the pack and both are legitimate starting points.

Three blocks had genuinely drifted, and the node's words won where they were
better: /install's hero ("You pay only for convenience" — deleted, it is now
false), /pricing's hero (shorter wording adopted back into the pack), and /why's
licensing block (rewritten by the owner; the change was narrowed to the one
clause that had become untrue).

**Block location names on the node are inconsistent** — `gj-install-hero` and
`gj-install-ladder` are single-dash while `gj-install--which` and
`gj-install--cta` are double. Anything that reaches for a block by name must try
both forms.

### 7.6 Left alone, and still open

**Seven orphaned blocks still hold `$39.99`** — `gj-home` (11), `gj-why` (24),
`gj-apps` (25), `gj-install` (26), `gj-leave-gmail` (27), `gj-families` (29) and
`gj-small-business-backups` (100). None appears in any page layout: they are
whole-page bodies left from `specs/implemented/getjoinery_content_to_db.md`, plus
one duplicate. Nothing renders them, so nothing was changed; they are recorded
here because they will surface in any future search for the fee.

**A free order has never run on this product.** One $0 order end to end,
checking `ord_payment_method = 'free'` and a `cvp_customer_cloud_provisions`
row. The product is active and the site now advertises it, so this path is live
and unproven.


---

## 8. Shipped 2026-09-07 — the subscription promise

`/page/pricing` said, in bold: **"We will never charge a subscription for the
core product."** Managed hosting is $12.99 a month. Shipping Managed against
that sentence would have made the site a liar on the one subject where it is
asking to be believed.

**The commitment, in the owner's words:** where we charge subscriptions, we
provide the same service as self-hosted, or explain why. On the site:

> **Anything we charge a subscription for, you will be able to run yourself
> instead.** If that is ever not true of something we sell, we will say so
> plainly and explain why.

This survives Managed, because Managed sells the *running* of software anyone
can self-host for nothing. It would not survive a feature that exists only on
our servers — which is precisely what the sentence exists to stop us doing
quietly. It is a harder promise to keep than "no subscriptions", and a much
easier one to keep honestly.

`/page/why` had already got it nearly right ("no subscriptions without a self
hosting option"). That wording is canonical; the other pages were brought to it
rather than a new phrase being invented for each.

### 8.1 What changed — 9 fields, 8 blocks

- **pricing "How Joinery makes money"** — the bold promise itself.
- **home "Stop renting your own data"** — the flat `No subscription` heading,
  which is the same promise in miniature, becomes *No subscription you are
  locked into*, and the paragraph under it carries the commitment.
- **why "How we make money"** — the canonical sentence, extended with the
  second half: that we explain ourselves if it ever stops being true.
- **families, leave-gmail** — "there is no subscription to us" becomes a
  statement about self-hosting, which is what it was always describing.

### 8.2 Three things §7 left stale

Found only because this pass searched for *claims* rather than for the literal
price. §7's sweep matched `$39.99`; these sentences never carried it:

- **install FAQ** — "if you paid for the install there is no subscription to
  unwind". Nobody pays for the install.
- **pricing FAQ** — "the only things behind money are the optional install
  service, two optional plugins, and business use".
- **the pricing page's own `<h1>`** — *"Free software, paid convenience"*. The
  convenience is free now, so the heading contradicted the page beneath it. It
  is *"Free for personal use, paid for business"*.

**The lesson worth keeping: a price sweep is not a claim sweep.** Removing a
price leaves behind every sentence that described what the price bought.

### 8.3 Verified live

Applied to getjoinery.com 2026-09-07, 9 fields, no drift, re-check reports
everything current. Against the running site, across ten pages: `never charge a
subscription`, `Free software, paid convenience`, `optional install service`
and `if you paid for the install` all appear **0 times**, and each new sentence
renders where it should.

Managed hosting is still named nowhere on the site. This change makes the
site's promise survive Managed; it does not announce it.


---

## 9. Shipped 2026-09-07 — the orphaned blocks retired

§7.6 recorded seven unreferenced blocks still holding `$39.99`. A full sweep
found **24** unreferenced `gj-` blocks, from two eras:

- **15 whole-page bodies** — `gj-home`, `gj-about`, `gj-terms` and the rest,
  one custom_html per page, from `specs/implemented/getjoinery_content_to_db.md`.
  Superseded when the site moved to a block per section.
- **8 single-dash duplicates** — `gj-small-business-hero` and friends, replaced
  by the `gj-small-business--hero` rows that `apply_pages_update.php` created.
- **1 block dropped from a layout** — `gj-nextcloud-alternative--complaints`,
  left behind by that page's rewrite exactly as that script's notes said it
  would be.

**Checked before deleting anything:** those blocks were designed to be rendered
*by name* from theme view files, so "not in a page layout" would not have been
enough on its own. The getjoinery theme now contains only `page_marketing.php`
and `components/`; no PHP anywhere in `public_html` mentions a `gj-` slug; and
the bare URLs (`/install`, `/about`, `/terms`) 404 or redirect to their
`/page/…` equivalents. Nothing rendered them.

`retire_orphan_blocks.php` (1.0) **soft-deletes** — `pac_delete_time` stamped,
rows untouched, one UPDATE to bring any back. It computes the orphan list on
every run rather than carrying ids, so a block put back into a page is spared;
proven by adding `gj-home` to a layout and watching the list drop from 24 to 23.
It refuses on a layout that is valid JSON but not a list of ids, and refuses if
no page names any block at all — the shape a broken read takes, which would
otherwise empty the site.

The content was dumped before the write and is kept at
`content_packs/getjoinery/retired_blocks_2026-09-07.json` (24 blocks, 185 KB).

**After:** 73 `gj-` blocks live, 0 unreferenced, and no block anywhere in the
database still quotes the fee. All twelve pages return 200 with their headings
intact.
