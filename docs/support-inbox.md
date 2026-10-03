# Customer Support: SMS, email and ticket updates

**Customer Support → SMS & Email** is a shared inbox like the WhatsApp one: one conversation
per phone number or email address, with Unassigned / Mine / All open / Closed views.
Replying to an unassigned conversation takes it. **New message** starts an SMS or an email
to anyone, and the customer's page in the Agent Workspace has SMS and Email buttons.

Each customer's page in the Agent Workspace also shows a **Timeline**: every call, logged
contact, WhatsApp, SMS and email message, ticket and follow-up, newest first.

All of this is set up in **Support Settings → SMS, email & customer updates**.

## SMS (Africa's Talking)
Uses the username and API key saved under Telephony.
1. Tick **Send and receive SMS**. Enter your approved **sender ID** (for example
   `MANGONET`) or shortcode, or leave it blank to use Africa's Talking's default.
2. Customers can only text you back on a **shortcode or two-way number**. A sender ID only
   sends. Ask Africa's Talking for a two-way shortcode if you want replies.
3. Copy the **SMS callback URL** from settings into the Africa's Talking dashboard, both as
   the shortcode's incoming-messages callback and as the delivery-reports callback. Treat
   it like a password.

## Email
1. Tick **Email inbox** and enter the support address, e.g. `support@mangonetonline.com`.
   Replies are sent through **Admin → Email Settings** (SMTP), with the support address
   as Reply-To, so the customer's answer comes back to the inbox.
2. Create the mailbox or forwarder in cPanel, then **cPanel → Email → Forwarders → Add
   Forwarder**: address = the support address, destination **Pipe to a Program**:
   ```
   fieldpulse.mangonetonline.com/scripts/email-inbound.php
   ```
   To also keep a copy in a real mailbox, add a second forwarder to that mailbox.
3. Send a test email to the address and check that it appears in the inbox within a few
   seconds. If it doesn't, check that the script is executable
   (`chmod 755 ~/fieldpulse.mangonetonline.com/scripts/email-inbound.php`) and look for an
   `error_log` file in the `scripts` folder.

Auto-replies, bounces and mailing-list mail are ignored, and the inbox never auto-replies
to email, so two auto-responders can't loop. Attachments are not stored; the message
notes how many there were.

## Ticket updates to customers
Tick **Text customers about their fault reports**. Each customer ticket then sends at most
one message per stage:
- **Logged:** when the ticket is created.
- **Engineer assigned:** when it moves to assigned or in progress.
- **Resolved:** when it moves to resolved or awaiting confirmation.

Stages are never repeated or sent backwards, and only tickets logged after you switch this
on get messages. Messages go out between 07:00 and 21:00; overnight changes are sent in
the morning. They go by SMS, or by WhatsApp when you choose that and the customer has
chatted in the last 24 hours. SMS updates are filed in the customer's SMS conversation,
so a reply lands next to the update it answers.

Updates are sent right after a ticket is saved in FieldPulse. Changes made elsewhere (the
OLT poller, bulk updates) and anything held overnight are sent by
`/api/support-housekeeping`, so run that cron **every 5 minutes**:
```
*/5 * * * * curl -m 120 -fsS -o /dev/null -H "X-Cron-Token: SLA_TOKEN" https://fieldpulse.mangonetonline.com/api/support-housekeeping 2>> $HOME/logs/fieldpulse-cron.log
```
