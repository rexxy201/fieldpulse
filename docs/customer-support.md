# Customer Support: setup on the live server

The module lives under **Customer Support** in the sidebar: Agent Workspace, Supervisor
Dashboard, Interactions, Calls, WhatsApp, Follow-ups and Support Settings. Its database
tables are created automatically on the first page load after deploy.

## Roles
- **Agents:** any role with *Customer Support: view*.
- **Supervisors and the manager:** *view all* (every call and chat, the dashboard) and
  *manage* (Support Settings). The built-in **CX Manager** role has both.

## Business hours
**Support Settings → Business hours.** Outside these hours calls go straight to voicemail
(nothing rings) and new WhatsApp chats get the closed reply. Leave every day unticked to
stay open around the clock.

## Voice (Africa's Talking)
1. **Support Settings → Telephony**: choose Africa's Talking and enter the username, API
   key and voice number, then save.
2. Copy the **Callback URL** shown there into the Africa's Talking dashboard as the voice
   number's callback URL. Treat it like a password.
3. Recordings are saved outside the website folder, in `~/fieldpulse-recordings`.

## WhatsApp
**Support Settings → WhatsApp**: choose the provider and save, then copy the
**Webhook URL** into the provider.
- **Africa's Talking:** enter the WhatsApp number. The username and API key come from
  Telephony.
- **Meta WhatsApp Cloud API:** enter the phone number ID, a permanent access token and the
  app secret. All three are required. In the Meta app, paste the URL and the **Verify
  token** under WhatsApp → Configuration → Webhook, and subscribe to `messages`.

## Cron (cPanel → Cron Jobs)
Uses the same SLA token as the other jobs:
```
*/30 * * * * curl -m 120 -fsS -o /dev/null -H "X-Cron-Token: SLA_TOKEN" https://fieldpulse.mangonetonline.com/api/support-housekeeping 2>> $HOME/logs/fieldpulse-cron.log
```
This downloads call recordings, deletes recordings older than the retention period, and
removes raw provider logs after 30 days.
