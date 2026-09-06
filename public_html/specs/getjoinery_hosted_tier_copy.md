# getjoinery.com copy: two choices

**Status:** Draft, 2026-09-06 (rewritten the same evening after the owner cut
the offering to two). Decisions in §2 are taken; what remains is writing.
**Companion:** `hosted_trial_provisioning.md` (the Managed product, with the
2026-09-06 decisions block at its top),
`content_packs/getjoinery/apply_hosted_product.php` (the product row, 2.0).

## 1. The offering, in one breath

getjoinery sells two things:

- **Self-hosted.** Free. Your machine or your cloud account, your accounts
  for mail and storage. The Linode StackScript is the easy way in and is
  presented as a *method* under this choice, never as a tier of its own.
- **Managed.** $12.99 a month, billed from the day you sign up. We host it,
  send your mail (1,000 a month), keep your backups (10 GB), and rebuild
  your site from those backups if it is ever lost.

Nothing else is for sale on the hosting axis. The $39.99 "we install it on
your own cloud" product is retired; the business licences are a different
axis (who you are, not how it runs) and stay where they are.

Why two and not three: a person either will open a cloud account and a mail
account — and then the free StackScript install is easy — or will not, and
then an automatic install on *their* cloud helps nobody. The middle rung sat
on that line and served neither side.

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
   sets $12.99, retires the setup line) and deactivate the $39.99 product on
   its product page.

`apply_pages_update.php` has not yet been run on the node as of this draft, so
check what is actually live before assuming a block exists to edit.
