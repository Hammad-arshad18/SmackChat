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
- Push (VAPID) and a PWA (`public/manifest.webmanifest`, `public/sw.js`). Theme: light, dark, system.
- On-device AI (`ai.js`): Chrome Gemini Nano first, otherwise the Transformers.js worker. Rewrite, draft, and summarize run locally; no AI request ever reaches the API. Models are always 4-bit (`model_q4.onnx`) and cached in `transformers-cache`; phones use SmolLM2 (135M/360M), desktops may use Qwen. When a model cannot start, the next candidate runs in a fresh worker. HTML responses send COOP/COEP so WASM inference can use threads.

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
| People | `MemberController`, `InvitationController` |
| Attendance | `AttendanceController`, `AttendanceService` |
| Schedule | `ScheduleController` |
| Billing | `BillingController`, `config/smackchat.php` |
| Push | `PushController`, `resources/js/push.js` |
| Live | `office.js`, `realtime.js`, `AblyPublisher` |
| On-device AI | `resources/js/ai.js`, `resources/js/workers/ai.worker.js` |
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
