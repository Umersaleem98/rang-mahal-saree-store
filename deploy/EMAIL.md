# Transactional email

Sender requested: Rang Mahal <info@rangmahal.site>. Provision the mailbox and verify ownership before enabling SMTP; never commit credentials. Use cPanel Connect Devices secure SMTP hostname/port, certificate verification enabled, MAIL_SCHEME=smtps for implicit TLS on 465, or smtp with STARTTLS on 587. MAIL_FROM_ADDRESS and MAIL_USERNAME must match the authorized mailbox. MAIL_FROM_NAME="Rang Mahal". Local uses authenticated SMTP over certificate-verified implicit TLS on port 465. Production uses the hosting account’s own SMTP relay with certificate-verified STARTTLS on port 25, because outbound connections to the mailbox SMTP service time out on this plan. The `hosting` mailer requires TLS and never sends mailbox credentials to that relay.

The additive 2026_11_01_000000_order_email_outbox migration stores an immutable email snapshot in the same database transaction as order placement, real status transitions, or changed shipment/payment details. Existing orders are not backfilled or emailed. Checkout retries and identical status/detail saves do not enqueue duplicates. HTML and text receipts contain real order lines, charges, reference, verified tracking link, actual courier/ETA when supplied and policy information. Catalogue edits do not email customers or alter historical order lines.

Install a one-minute cron after SMTP configuration, preserving other cron entries:

    * * * * * /usr/bin/flock -n /home/jjt1xvidnzva/apps/rangmahal/storage/framework/order-emails.lock /usr/local/bin/php /home/jjt1xvidnzva/apps/rangmahal/artisan orders:send-emails --limit=50 >> /home/jjt1xvidnzva/apps/rangmahal/storage/logs/order-email-worker.log 2>&1

Confirm actual PHP/flock paths on the host first. Worker claims each row with a database lock and lease, retries with bounded exponential backoff, and stops after eight attempts. Monitor unsent attempts>=8 and private application logs. SMTP acceptance records sent_at; it does not prove inbox delivery. A process failure between SMTP acceptance and recording sent_at can result in a duplicate; no SMTP implementation can promise exactly-once inbox delivery. No exceptions roll back a successfully placed order because sending is outside checkout.

SPF must include the actual outbound sender without duplicate SPF records; publish DKIM from cPanel and aligned DMARC initially in monitoring mode after inspecting existing DNS. Do not overwrite existing mail provider MX/SPF records blindly. Confirm forward/reverse DNS and host relay configuration with GoDaddy. Send a test to a business-authorized mailbox and inspect Authentication-Results for SPF/DKIM/DMARC, plus Inbox/Spam placement. Inbox placement cannot be guaranteed. Do not send marketing broadcasts through order notifications.

Tests cover queueing once for checkout retries, professional receipt content, status-update deduplication, delivery failure/backoff/recovery, and no stock mutation on email retry.


## Active integration — November 2026

Business email and the owner dashboard login use info@rangmahal.site. Passwords live only in private .env/credential files and the owner password is hashed in MySQL. Credential changes increment auth_version to invalidate old dashboard sessions; other staff accounts retain their roles and access. Instagram is now https://www.instagram.com/rangmahal.site?vrfl=MWl3MzFzc3I0a3k4dg== and the announcement animation runs right to left.

Production MAIL_MAILER=hosting uses the certificate-verified server hostname on port 25 with require_tls=true; relay authorization is provided by the hosting account rather than mailbox password. Local MAIL_MAILER=smtp uses GoDaddy’s documented smtpout.secureserver.net:465 with mailbox authentication and normal certificate checks. Never set verify_peer=false or disable certificate checking. Empty MAIL_URL values are ignored so they cannot override the configured SMTP transport.

The production cron runs orders:send-emails every minute with flock. The local Windows task “Rang Mahal Local Order Emails” runs scripts/send-local-order-emails.ps1 every minute while the current Windows user is logged in; that helper refuses any APP_ENV other than local. XAMPP/MySQL must be running for local orders. Both use the same durable, deduplicated outbox as before. Monitor unsent rows, attempts and private logs. Existing orders are not backfilled; no bulk marketing subscription is created.

HTML emails embed public/images/rang-mahal-email-logo.png as a CID attachment so the brand mark is packaged with the message; a plain-text alternative is included. Order placement and every real supported status have distinct headings and explanations. Payment/shipment updates report only recorded details. Receipts include the website, reply address, line totals, shipping, total, reference and secure tracking link. Mailbox setup checks are clearly labelled as tests and do not insert orders or alter inventory.

Public DNS currently authorizes GoDaddy through one SPF record and has a DMARC quarantine policy. Check received Authentication-Results rather than assuming inbox delivery. DKIM selector/signing and mailbox folder placement should be verified after test delivery. A valid SPF/DMARC result and professional content still cannot guarantee Inbox placement at every recipient provider.

Provider references: [secure mailbox SMTP](https://www.godaddy.com/help/set-up-third-party-plugins-or-websites-using-smtp-settings-42788) and [cPanel hosting relay](https://www.godaddy.com/en-uk/help/send-form-mail-using-an-smtp-relay-server-953).
