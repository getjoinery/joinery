# SMTP2GO Affiliate Signup

**Status:** Not started — reminder / task, no code involved.

## What this is

Sign Joinery up for the SMTP2GO affiliate program so outbound mentions of SMTP2GO
(docs, setup wizard help text, blog posts, the mail topology guidance) can carry a
referral link that pays a commission.

## What has to happen

1. Apply at `https://dash.partnerstack.com/application?company=smtp2go`. The
   "Become an affiliate" button on `https://affiliates.smtp2go.com/` leads to the
   same form. This is a separate PartnerStack account — there is nothing to enable
   inside the SMTP2GO account itself, and being a paying SMTP2GO customer is not
   a requirement.
2. Accept the affiliate terms during signup.
3. After approval, collect the unique affiliate link from the PartnerStack
   dashboard (`https://dash.partnerstack.com/handshake`). Links and campaigns are
   created and managed there.
4. Add a payout method: PayPal or Stripe.
5. Decide where the link goes in Joinery, then place it. Candidates: mail setup
   docs, the setup wizard's outbound-mail step, and any public page that names
   SMTP2GO as an option.

## Terms as of 2026-09-07

- 20% recurring commission on all payments a referred account makes during its
  first 12 months.
- 90-day cookie, re-set on every click of the link.
- Commissions tallied at month end, verified and paid the following month.

## Open question

The program is written for referring an audience the affiliate has **no billing
relationship with**. Managed Joinery customers are billed by us and send through
our infrastructure, so they almost certainly do not qualify as referrals. The
affiliate link is therefore for self-hosted operators and readers who set up their
own SMTP2GO account.

If the goal was ever to earn on sending for managed sites, that is the
[reseller program](https://www.smtp2go.com/resellers/), not this one — a separate
evaluation, not part of this task.
