# Production checklist

The code is ready for real users; the items below are infrastructure and
business decisions that cannot live in the repository. Work top to bottom.

## Before the first customer

1. **Environment** — `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` =
   the exact public URL, `MAIL_*` pointed at a real SMTP provider, and a
   `MAIL_FROM_ADDRESS` on your own domain. `LOG_STACK=daily` (default in
   `.env.example`).
2. **HTTPS** — terminate TLS at the proxy and keep `APP_URL` on `https://`.
   The app forces HTTPS URLs and secure session cookies in production.
3. **Reverse proxy** — the app trusts no proxy headers by default. If you put a
   load balancer or CDN in front of it, add its addresses in
   `bootstrap/app.php` (`trustProxies(at: [...])`). Never use `'*'`: it lets a
   client spoof the IP used by office presence and rate limits.
4. **Backups** — `php artisan smackchat:backup` writes `storage/app/backups`.
   Run it daily via the scheduler and copy that directory off the server with
   your hosting provider's off-site backup or an rsync job. Back up `.env`
   separately: `APP_KEY` decrypts server-side posts and old URLs.
5. **Scheduler** — add one cron entry:
   `* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1`.
   Without it, history pruning, audit retention, and backups never run.
6. **Queue** — nothing is queued today; push and email run after the response
   using `defer()`. If you later move work to jobs, run `queue:work` under
   supervisor and add `php artisan queue:restart` to `deploy.sh`.
7. **Health** — point the uptime monitor at `/up`. It fails when MySQL is
   unreachable.

## Accounts and services to connect

- **Error tracking** — add Sentry, Flare, or Bugsnag. Nothing reports to a
  dashboard today.
- **Payments** — the Plans page only flips a flag; no money moves. Wire Stripe
  (or your provider) with a webhook, invoices, seat reconciliation, and a
  grace period before enabling the “Forever archive” price.
- **Email deliverability** — SPF, DKIM, and DMARC for the sending domain, or
  verification and reset mail will land in spam.
- **Huddles** — audio/video is peer to peer WebRTC and needs HTTPS. Set
  `SMACKCHAT_CALL_ICE_SERVERS` to a comma-separated list; a STUN server is
  enough inside one office network, but remote people need a TURN server
  (for example coturn) or calls fail behind strict NAT/firewalls. There is no
  media server: every participant sends one stream to every other participant,
  so keep `SMACKCHAT_CALL_GROUP_LIMIT` at 8 or lower. The free plan is
  one-to-one; groups belong to the archive plan.
- **Ably** — `ABLY_KEY` is the fallback for every workspace. An owner may paste
  the office's own Ably key in **Settings → Realtime**; it is encrypted with
  `APP_KEY` and never returned by the API. Keep `APP_KEY` backed up or that key
  becomes unreadable.

## Compliance

- Publish a privacy policy and a DPA. Messages are end-to-end encrypted, but
  attachments, profiles, attendance GPS/IP, and push endpoints are personal
  data stored on the server.
- Decide attendance retention (`SMACKCHAT_ATTENDANCE_RETENTION_DAYS`, `0` keeps
  forever) and audit retention (`SMACKCHAT_AUDIT_RETENTION_DAYS`).
- Account deletion and personal-data export are in **Settings → Account**.
  A sole owner's workspace is deleted with the account; shared workspaces must
  be handed over first.

## Release checks

- `php artisan test` (SQLite) and the `Migrate on MySQL 8` CI job must be green.
- `php vendor/bin/pint --test`, `composer audit`, `npm audit --omit=dev`.
- After changing JS dependencies, regenerate the lock so CI gets the Linux
  native binaries (Rollup, esbuild, Tailwind oxide, lightningcss):
  `rm package-lock.json && npm install --package-lock-only --os=linux --cpu=x64 --libc=glibc`
  then `npm ci && npm run build`. A lock generated with a plain `npm install`
  on macOS drops those packages and breaks the Linux build.
- Smoke-test the composer after a Tiptap upgrade: send a message with bold,
  italic, underline, a list, a link, an @mention, and an emoji.
- Attachments are opaque-linked (`/api/attachments/v1.…`); numeric ids return
  404 by design.
