# Email

## How do I set up outgoing email?

Configure an email provider first; a new site has none and sends nothing until you do. Without one, password reset cannot work, and a forgotten password means a trip to the server.

Open `/admin/admin_settings_email`, or the Email step of the setup wizard. Choose your provider, save its credentials, send the test message, and confirm it arrived.

See: docs/email_system.md > Configuring a provider is the first task on a new deployment

## Which email providers does Joinery support?

Mailgun, SMTP (any provider that offers SMTP), SendGrid, Amazon SES, Postmark, Brevo, Resend, Mailjet, and a Connected Email Account (Gmail, Microsoft 365, Yahoo, iCloud or Fastmail). SMTP2GO is also supported, set up with a single API key.

You can also set a fallback service (`email_fallback_service`) that is tried when the main one fails.

See: docs/email_system.md > Built-in Providers

## Can my server send email by itself, without a provider?

Not in a normal setup. Most hosting providers, Linode included, block outbound port 25 at the account level, so a mail server on your machine cannot deliver. Name a provider.

Sending straight from your own server is an advanced setup: it needs your hosting provider to unblock port 25, a matching reverse DNS (PTR) record, and you take on your server's sending reputation. Receiving mail on your own server does not change this; outgoing mail still goes through a provider.

See: docs/email_system.md > Overview

## Can I send the site's email through my Gmail or Microsoft 365 account?

Yes. Connect the account, then choose **Connected Email Account** as the email service. All site mail is then sent through that account, and the From address is always that account's address.

- A Microsoft account needs permission to send. If it was connected without it, the site refuses to send and says "Reconnect to allow sending."
- Some Microsoft 365 organisations turn off SMTP sending; the tenant admin must turn it on, or use a provider such as Mailgun or SES.
- A connected account has its provider's sending limits. When the provider is rate-limiting, the site says so; that is the signal to move to a dedicated provider, which is one setting change.

See: docs/email_system.md > Connected account as the system provider

## Emails are not going out. How do I find out why?

1. Turn on the `email_debug_mode` setting.
2. Read the log at `/admin/admin_debug_email_logs`. Each row is one step: which service a message went to, a fallback, or a send held back by test mode or dry run.
3. Check that `email_test_mode` and `email_dry_run` are off. Test mode sends every message only to `email_test_recipient`; dry run sends nothing at all.

Messages that fail on both the main and the fallback service are queued and retried later.

See: docs/email_system.md > Debug Tools

## How do I receive email at my own domain?

Use the Mailbox plugin. It is part of the default bundle on a new site.

1. On a server with its own mail stack, run the plugin's `provisioning/install_email.sh` as root, once. The Plugins page (`/admin/admin_plugins`) shows **Needs setup** with this command until it is done. A site in a container has no mail server and receives through a relay.
2. Open **Emails > Mailbox**. It opens on the **Setup** tab.
3. Add your domain (**+ Add Domain** on the **Accounts** tab, or the one-click action on the Setup tab), then publish the DNS records the Setup tab shows.
4. Add a mailbox for the address, and use the Setup tab's end-to-end check to send a real message and watch it arrive.

See: plugins/mailbox/docs/overview.md > Installation

## How do I forward an address like info@ to my personal email?

1. Go to **Emails > Mailbox > Accounts**.
2. Click **+ Mailbox** on your domain.
3. Enter the name (`info`), choose the `forward` delivery mode, add the destination addresses, and save.

Each destination receives forwards only after the person at it confirms. Choose `forward_and_store` to forward and also keep a copy, or `store` to keep mail in the mailbox reader without forwarding.

See: plugins/mailbox/docs/overview.md > Delivery Modes

## What DNS records do I need for email?

The Setup tab lists the exact records your domain still needs, with copy-ready values, and checks them live. A fully configured domain has:

- An MX record pointing at the server (or relay) that receives your mail.
- An SPF record on the domain that authorizes your outbound provider, ending in `-all`.
- The provider's DKIM record for the domain.
- SPF and DKIM records for `mail.<domain>`, which the site's own automated mail sends from.
- A DMARC record at `_dmarc.<domain>`.

The Setup tab can also publish the whole set through your DNS host, or you can publish the records by hand.

See: plugins/mailbox/docs/domain_onboarding.md > The record set

## Mail sent to my domain is not arriving. What should I check?

1. The Mailbox **Logs** tab: did the message reach the site at all?
2. That the address (alias) and the domain are both enabled.
3. The MX record (`dig MX yourdomain.com`), that port 25 is open, and that Postfix is running.
4. The site's `error.log`.

If the sender gets "User unknown in local recipient table", the domain is in Postfix's `mydestination`. The domain edit page shows a red "Conflict" badge; run `install_email.sh` to fix it.

See: plugins/mailbox/docs/overview.md > Troubleshooting

## Why is my mail landing in spam?

Check, in order:

1. SRS is enabled.
2. rspamd is running, a DKIM key has been generated, and its DNS record is published.
3. SPF includes your server's IP address.
4. Reverse DNS (PTR) is set for the server.
5. Your server's IP is not on a block list (check at mxtoolbox.com).

See: plugins/mailbox/docs/overview.md > Troubleshooting
