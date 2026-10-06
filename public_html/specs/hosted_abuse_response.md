# Abuse on hosted sites: make it not worth it, and act fast

**Status:** Draft, 2026-10-04. Nothing here is built. Split out of the starter
tier spec. It applies to every hosted site; the starter tier is where the
risk is highest, because it is the cheapest way in.

**Owner decision, 2026-10-04: there are no free accounts.** Every hosted site
is paid for before it exists. That alone removes most of the reason to abuse
the platform. This spec covers the rest.

**What abuse looks like.** Someone pays for a hosted site, most likely the
cheapest one, a starter site, in order to:

- host a phishing page, such as a fake bank login. This is the most common
  case, and the page editor is enough; no code is needed.
- host malware or pirated files behind public links.
- send spam through our mail account.
- run code, through a plugin upload, that scans or attacks other servers,
  or mines cryptocurrency.

**How it reaches us, and why it matters.** Security firms and banks report
a phishing page to whoever owns its IP address, which is Linode. Linode opens
a ticket on **our** account and expects a quick fix. Tickets that keep coming
or go unanswered can get the account restricted, and every node we run is in
that account, including customers' own servers. Spam reports reach SMTP2GO
and put our master account, and with it every hosted site's mail, at risk.
**One abusive site can endanger the whole fleet.** That is the reason for
this spec. It is also one more reason for the Linode exit principle.

**1. Make it expensive to try.** Each part raises the cost of an abusive
signup. None of them gets in a real customer's way.

| Part | What it does | Status |
|---|---|---|
| Payment before the site exists | Abusers want throwaway sites in bulk, and a real charge per site is the cost they avoid. Stripe's own fraud screening (Radar) runs on every charge. | built: the hosted tier activates on payment |
| One card, one email, a few sites | A limit on hosted sites per verified email and per card, so one stolen card cannot buy fifty | new, small |
| Acceptable-use terms | The terms say what is not allowed and that we may suspend without notice | new, writing |

A stolen card still costs us. A chargeback carries Stripe's dispute fee as
well as the lost payment. That is why the per-card limit and fast suspension
matter even with payment up front.

**2. Cap what an abuser can do.** These mostly exist already, or are in this
spec:

- **Mail:** SMTP2GO's 1,000-a-month allowance per subaccount, plus the
  provider's own spam controls on that subaccount (hosted tier § Outbound
  mail).
- **No mail sent from the box itself:** the box drops outbound port 25, so
  all mail goes through SMTP2GO, where it is counted and policed (`own_mail_server_sending` § 4;
  `multi_tenant_docker_hosts` WP5.5).
  Joinery Direct (instance-to-instance delivery over HTTPS) is unaffected.
  Sending from a machine's own mail server is, on every install, the
  enterprise plugin's job (spec `own_mail_server_sending`).
- **CPU:** on a multi-tenant host, the CPU ceiling (`multi_tenant_docker_hosts` WP3)
  makes mining slow and keeps it from hurting
  neighbours.
- **Outbound traffic per site** is in the multi-tenant host's measurements
  (`multi_tenant_docker_hosts` WP1), so a site scanning
  the internet shows up as an outlier. Limits on it (a new-connection rate,
  a UDP drop and a speed ceiling on every install) are spec
  `node_outbound_and_transfer`; on a multi-tenant host, a lower ceiling and
  an alert when a site passes its monthly share are spec
  `site_outbound_limits`.

**3. Find it before Linode does.**

- A daily check of every hosted site's domain against Google's lookup of
  known phishing and malware sites. **Check the terms first (S27):** the
  free Safe Browsing API is for non-commercial use, and the commercial
  product is Web Risk, which is priced. A hit raises an operator alert naming
  the site.
- A published abuse address on getjoinery, so a report can reach us first.
  Incoming reports become operator alerts.
- Outbound-traffic outliers on a multi-tenant host raise an operator alert.

**4. Act in one click.** A **Suspend** action on the node page, for any
hosted site:

- **On a multi-tenant host** (a starter site), it stops that one container and holds
  it stopped (`multi_tenant_docker_hosts` WP7). The box's proxy shows a plain
  "This site is suspended" page for the domain (`multi_tenant_docker_hosts` WP8),
  so whoever reported it sees that it was acted on.
- **On a machine of its own**, it shuts the instance down, as the end of an
  unpaid grace period already does. The domain stops answering; there is no
  proxy in front to show a page.
- Either way, it closes the site's SMTP2GO subaccount for sending, and pauses
  its fleet backups and uptime checks so a site stopped on purpose does not
  raise alerts. Its data and backups are kept.
- It records who suspended the site, when and why, emails the customer, and
  puts the reason on the customer's sites page. No such record exists today;
  it is new.
- **Unsuspend** reverses each step.

Suspend is the end-of-grace switch with a different reason. Deletion stays a
person's decision, as everywhere else.

## Testing

| Check | Pass |
|---|---|
| a buyer tries to buy more hosted sites than the per-email or per-card limit | refused, with the limit named |
| the operator presses Suspend on a starter site, waits two health-check ticks, then Unsuspend | the site stays stopped with the suspended page shown and mail stopped; afterwards the site, its data and its mail are back unchanged |
| the operator presses Suspend on an own-server site, then Unsuspend | the instance is shut down and mail stopped; afterwards it is back unchanged |
| a domain on the phishing-lookup test list | the daily check raises an alert naming the site |
| a report arrives at the abuse address | an operator alert names it |

## Docs to update when this lands

- `plugins/server_manager/docs/hosted_tier.md`: the acceptable-use terms,
  the per-buyer limits, Suspend, and the abuse address.
- `plugins/server_manager/docs/overview.md`: **Suspend** on the node page and
  the phishing-lookup alert.
