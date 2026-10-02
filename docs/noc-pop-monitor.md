# NOC: POP Monitor

**NOC → POP Monitor** lists each point of presence. Its router's public IP is pinged every
minute. A POP shows **Down** after its set number of failed checks in a row (default 3, so
about 3 minutes), and **Online** again on the first good check. Some packet loss, or replies
slower than the "slow above" setting, show as **Degraded**.

Down and recovery alerts go to NOC supervisors and admins, in-app and by email.

## Setup (cPanel)
1. Add the cron job, under **cPanel → Cron Jobs**, every minute:
   ```
   * * * * * php $HOME/fieldpulse.mangonetonline.com/scripts/pop-check.php >> $HOME/logs/fieldpulse-cron.log 2>&1
   ```
   It prints only when a POP goes down or recovers, so the log stays quiet when nothing
   changes.
2. Add each POP: give it a name and the router's **public** IP, and link it to its hub.
   Customers in that hub then count as affected.
3. The router must answer ping from the internet. On MikroTik, check that
   `/ip firewall filter` doesn't drop ICMP on the WAN interface. To limit exposure, allow
   ICMP only from the server's IP.

The "fallback port" is only used if ping can't run (e.g. the "Check now" button when the
web server forbids running programs). It tries a TCP connection instead, by default to
Winbox on 8291.
