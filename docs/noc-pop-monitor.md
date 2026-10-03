# NOC: POP Monitor

**NOC → POP Monitor** lists each point of presence. Its router's public IP is pinged every
minute. A POP shows **Down** after its set number of failed checks in a row (default 3, so
about 3 minutes), and **Online** again on the first good check. Some packet loss, or replies
slower than the "slow above" setting, show as **Degraded**.

Down and recovery alerts go to NOC supervisors and admins, in-app and by email.

## Setup (cPanel)
1. Add the cron job, under **cPanel → Cron Jobs**, every 5 minutes (this host refuses
   anything more frequent):
   ```
   */5 * * * * php -q $HOME/fieldpulse.mangonetonline.com/scripts/pop-check.php >> $HOME/logs/fieldpulse-cron.log 2>&1
   ```
   Each run keeps going for about five minutes and checks once a minute, so POPs are still
   checked every minute. `-q` stops cPanel's PHP from writing HTTP headers into the log.
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

## If the cron stops
The page warns when `scripts/pop-check.php` hasn't run for 5 minutes. After 10 minutes the
15-minute SLA cron (`/api/sla-check`) takes over: it checks the POPs itself and alerts NOC
supervisors and admins, at most every 6 hours, until the job runs again. That backup only
works if the SLA cron is set up.

To find the cause, open **cPanel → Terminal**:
```bash
crontab -l | grep pop-check        # is the line there, starting */5 * * * *?
ls -ld ~/logs                      # must exist, or the ">>" redirect stops the job running at all
tail -20 ~/logs/fieldpulse-cron.log
php -v                             # must say PHP 8.x; if not, use the full path below
php -q ~/fieldpulse.mangonetonline.com/scripts/pop-check.php; echo "exit: $?"   # takes ~4 minutes; expect only "exit: 0"
```
If plain `php` is an older version, put the full path in the cron line, e.g.
`/usr/local/bin/ea-php82 $HOME/fieldpulse.mangonetonline.com/scripts/pop-check.php`
(`ls /usr/local/bin/ea-php*` lists what's installed).
