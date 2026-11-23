# SmackChat

Office chat for one company workspace. Laravel 12 API, Vue 3, Vite, PrimeVue, MySQL, Sanctum. Ably for live messages. Hostinger deploy via `deploy.sh` and `.github/workflows/ci-cd.yml`.

`README.md` is stale. It says message text is only encrypted at rest. User-written messages are end to end. Use this file, not the README, for behavior.

Do not scan `vendor/` or `node_modules/`. Start in the file map below.

## Product

- Sign in, or register with an email code (`AuthController`). A new account creates a workspace (`WorkspaceProvisioner`).
- Roles: owner, admin, member. Owner runs billing and is on Timesheets from the start.
- Channels: `public`, `private`, `dm` (slug `dm-{pair}`, `dm_key` is the two user ids), `attendance` (the Timesheets ledger).
- New workspace gets `#general` and `#timesheets`. A welcome post is `cipher=server`. Public channels gain each new member.
- Composer: bold, italic, underline, strike, lists, quote, code, links, emoji, @mentions, attachments, drafts in `localStorage` (`smack.draft.`).
- Threads, reactions, edit, delete, copy. Search is ⌘K (`CommandPalette.vue`) plus in-channel search.
- Today (`LoungeView`): shift, timer, unread, presence. Attendance heatmap, CSV export, schedule, holidays.
- Plans: `standard` keeps history `SMACKCHAT_RETENTION_DAYS` (90). `archive` is forever at `SMACKCHAT_ARCHIVE_PRICE_CENTS` (700) per person. Not a real card charge. `smackchat:prune-history` deletes old standard-plan rows.
- Huddles: WebRTC audio/video from the channel header. The server is only the switchboard. Free `standard` is one-to-one; `archive` adds groups up to `SMACKCHAT_CALL_GROUP_LIMIT` (8). In a DM the UI says "call"; in a channel it says "huddle". The callee gets a full-screen ring with a Web Audio ringtone, a title flash, and a browser notification when permitted. The call stage has a full-screen toggle and a 45-second ring / 60-second no-answer timeout.
- Realtime key: the owner can put the workspace's own Ably key in Settings. It is encrypted at rest, never shown again, and minted into short-lived tokens. Without a workspace or server key the app polls.
- Push (VAPID) and a PWA (`public/manifest.webmanifest`, `public/sw.js`). Theme: light, dark, system.
- On-device AI (`ai.js`, `agent.js`): Chrome Gemini Nano first, otherwise the Transformers.js worker. Rewrite, draft, and summarize run locally. Ask AI can run up to three actions on the device — draft, reply, rewrite, summarize, search, open, unread, or a clock punch. A draft or reply is sent, and a punch runs immediately. No AI request reaches the API. Models download once into `transformers-cache`; when one cannot start (memory/GPU), the next candidate runs in a fresh worker and the choice is remembered. Phones run WASM only: iPhone/iPad and Android under 6 GB get SmolLM2 135M (q8, ~140 MB), larger Android gets 360M first. A model that crashed the visible tab is recorded (`smack.ai.localAttempt` → `smack.ai.localBlocked`) and skipped; deleting the model in Settings clears that. A silent worker is stopped by a watchdog, and an idle or backgrounded phone worker is terminated to free memory.

## Pages

| URL | View |
| --- | --- |
| `/login` | `AuthView.vue` |
| `/invite/:token` | `InviteView.vue` |
| `/w/:slug` | Today, `LoungeView.vue` |
| `/w/:slug/c/:channel` | `ChannelView.vue` |
| `/w/:slug/attendance` | `AttendanceView.vue` |
| `/w/:slug/hours` | `HoursView.vue` |
| `/w/:slug/members` | `MembersView.vue` |
| `/w/:slug/settings` | `SettingsView.vue` |
| `/w/:slug/billing` | `BillingView.vue` |

Shell and nav: `Shell.vue`, `WorkspaceNav.vue`. Router: `resources/js/router.js`. API: `routes/api.php`.

## Chat behavior

- User posts send `sealed`, not a plaintext document. `messages.cipher` is `e2e` or `server`. Server posts (welcome, attendance checkout) stay `server`.
- Presenter returns `ref` and `parent_ref` (`OpaqueId`, `v1.` token). Address bar uses those. Numeric ids stay in JSON only.
- Open thread sets `?thread=<parent ref>` so refresh reopens it. Search and notifications use `m` and `reply` refs, then the thread replaces them.
- Desktop: thread is the right column; its reply field is inside the panel; the channel composer stays under the transcript. At `max-width: 1080px` the thread is `position: fixed; inset: 0` and the channel composer is hidden.
- Reactions broadcast `message.reacted` with `actor_id`. The client keeps the viewer's `mine` flag unless they are the actor.
- Ably channel `smackchat:ws:{workspaceId}:ch:{channelId}`, event `message`. No `ABLY_KEY`: the open chat polls. That is expected.
- Huddle signals ride the same channel on event `call` (ring, joined, left, signal, ended). SDP/ICE rows live in `call_signals` and are also polled over HTTP, so calls work without Ably. The channel capability is unchanged; only the server publishes `call`. Signaling is not end to end: SDP/ICE is readable by the server and cleared when the call ends.
- SDP must end with CRLF. Laravel's `TrimStrings` strips it, so the client appends it on send and receive and `CallService::signal` restores it before storing. Do not remove that guard: Chrome rejects the description with "Invalid SDP line" and the call connects to nothing.
- A new call also sends a Web Push (`WebPushNotifier::ring`) so a closed tab is notified. It has a short TTL, topic `call-{id}`, and an `expires_at`; the service worker shows Answer and Decline buttons on Android. Tapping it opens `/w/{slug}/c/{channel}?call={id}`; the client joins that call once and removes the query (`&decline=1` declines instead). When the call ends, `cancelRing` replaces the ring with "Missed call" for people who never joined. A closed web app can notify, but it cannot ring like a native phone.
- Decline: `POST /calls/{id}/decline`. In a DM it ends the call; in a channel it only tells the caller (`call.declined`).
- A call nobody answers ends after `SMACKCHAT_CALL_RING_SECONDS` (75). `smackchat:sweep-calls` runs every minute and does this even when the caller's tab is gone.
- WebRTC: no peer connection is built until the mic is open (a peer built earlier had no audio sender, so audio went one way). Every signal carries `_s` (the tab's session) and `_c` (the connection); a new value means the other side reloaded or rebuilt, and the old connection is replaced. A join deletes that person's old signals and always publishes `call.joined` (`rejoin: true` when already seated). Signal polling has its own limiter, `call-signals`.
- A workspace Ably key replaces `ABLY_KEY` for that office. Tokens are scoped to that workspace.
- E2E search does not send the query string. The API returns recent visible rows; the browser decrypts and filters.
- Push text for a user message is `New message`.
- Attachments are files the server can read. They are not end to end.

## Attendance

Punch actions: `check_in`, `break_in`, `break_out`, `check_out`. Checkout writes the day's hours into Timesheets. Schedule defaults: Mon–Fri, 09:00–17:00, workspace timezone. Holidays can repeat yearly. Logic: `AttendanceService`, `AttendanceCalculator`, `ScheduleInspector`.

## File map

| Area | File |
| --- | --- |
| Routes | `routes/api.php` |
| Messages | `MessageController`, `MessageWriter`, `MessagePresenter` |
| E2E | `app/Support/E2eSealer.php`, `resources/js/e2e.js`, `E2eController` |
| URL tokens | `app/Support/OpaqueId.php` |
| Channels | `ChannelController`, `ChannelAccess` |
| Huddles | `CallController`, `CallService`, `resources/js/stores/calls.js`, `CallDock.vue` |
| Realtime key | `RealtimeSettingsController`, `AblyPublisher` |
| People | `MemberController`, `InvitationController` |
| Attendance | `AttendanceController`, `AttendanceService` |
| Schedule | `ScheduleController` |
| Billing | `BillingController`, `config/smackchat.php` |
| Push | `PushController`, `resources/js/push.js` |
| Live | `office.js`, `realtime.js`, `AblyPublisher` |
| On-device AI | `resources/js/ai.js`, `resources/js/agent.js`, `resources/js/workers/ai.worker.js` |
| Chat UI | `ChannelView.vue`, `Composer.vue`, `resources/css/app.css` |
| Tests | `tests/Feature/OfficeChatTest.php`, `tests/Unit/*` |

## Run

```bash
docker compose up -d
php artisan migrate --seed
php artisan serve
npm run dev
php vendor/bin/pint --dirty
php artisan test --filter=Name
```

Serve is often `127.0.0.1:8001` when `:8000` is another app. Vite is port 5173. Seed: Northwind Studio. `ada@northwind.test` owner, `lin@northwind.test` admin, `grace@northwind.test` member. Password `password`. Timezone Asia/Dubai.

## Production ops

- `PRODUCTION.md` is the launch checklist. Read it before the first customer.
- Cron: `* * * * * php artisan schedule:run` runs history pruning, audit retention, `smackchat:sweep-calls` (every minute), and `smackchat:backup` (dump + files in `storage/app/backups`). Copy that directory off-site.
- No trusted proxy by default. Add real proxy IPs in `bootstrap/app.php`; never `'*'` (office presence and rate limits read the client IP).
- `SECURITY`: password reset at `/api/forgot-password` and `/api/reset-password`; account export/delete at `/api/me/export` and `DELETE /api/me`; audit at `GET /api/workspaces/{slug}/audit` (owner/admin).
- Tests: `SecurityTest` covers proxy spoofing, login lockout, reset, Ably capability, audit, export, deletion, backup, health.
