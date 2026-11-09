# SmackChat

Laravel 12 + Vue 3 + Vite + PrimeVue office app. MySQL, Sanctum, Ably, Web Push. SPA from Laravel. API in `routes/api.php`. Do not scan `vendor/` or `node_modules/`. `README.md` is wrong about encryption: user messages are end to end, not only encrypted at rest.

## Product

Company workspace. Roles: owner, admin, member. Channels: public, private, DM, and the private Timesheets channel (`attendance`). Register with an email code creates the workspace, `#general`, `#timesheets`, and a server welcome post. New members join public channels only.

Chat: rich composer, mentions, attachments, drafts (`localStorage` `smack.draft.`), threads, reactions, edit, delete. Today dashboard, attendance heatmap and CSV, schedule and holidays, people and invites, settings, plans.

Standard plan keeps 90 days (`SMACKCHAT_RETENTION_DAYS`) and `smackchat:prune-history` deletes the rest. Archive plan keeps history at $7 a person (`SMACKCHAT_ARCHIVE_PRICE_CENTS`). It only flips `workspaces.plan`. No card charge.

Seed: Northwind Studio, Asia/Dubai, password `password`. Ada `ada@northwind.test` owner, Lin `lin@northwind.test` admin, Grace `grace@northwind.test` member.

## Pages

`/login`, `/invite/:token`, `/w/:slug` Today, `/w/:slug/c/:channel` chat, `/attendance`, `/hours`, `/members`, `/settings`, `/billing`. Views live in `resources/js/views/`. Shell is `Shell.vue`.

## Chat

- User create/update body is `sealed`. Never a plaintext document. `messages.cipher` is `e2e` or `server`.
- `server` cipher is only welcome, seed, and checkout posts.
- Presenter adds `ref` and `parent_ref` (`app/Support/OpaqueId.php`, prefix `v1.`). URLs use those. Numeric ids stay in API JSON.
- `?thread=<parent ref>` survives refresh. `m` and `reply` are search/notification links.
- Desktop thread: right column, reply box inside the panel, channel composer under the transcript. ≤1080px: thread is `position: fixed; inset: 0`, channel composer hidden.
- Reactions: Ably event `message` with `message.reacted` and `actor_id`. Client must not treat someone else's reaction as `mine`.
- Ably channel `smackchat:ws:{workspaceId}:ch:{channelId}`. No key means polling, which is fine.
- E2E search filters on the device. Do not POST the search phrase.
- Push preview for user text is `New message`. Attachments are server-readable files.
- Keys: P-256 device key in `localStorage` `smackchat.e2e.v1.{userId}`, wraps in `channel_key_wraps`. Migration `2026_11_09_090000_add_end_to_end_message_keys.php`. Code: `E2eSealer.php`, `resources/js/e2e.js`.

## Attendance and billing

Punches: `check_in`, `break_in`, `break_out`, `check_out`. Checkout writes hours into Timesheets. Default hours Mon–Fri 09:00–17:00 in the workspace timezone. Holidays may repeat yearly.

## Where code lives

`MessageController`, `MessageWriter`, `MessagePresenter`, `ChannelController`, `ChannelAccess`, `MemberController`, `InvitationController`, `AttendanceController`, `AttendanceService`, `ScheduleController`, `BillingController`, `PushController`, `E2eController`. UI: `ChannelView.vue`, `Composer.vue`, `resources/css/app.css`, `office.js`, `realtime.js`. Tests: `tests/Feature/OfficeChatTest.php`, `tests/Support/E2eParty.php`, `tests/Unit/OpaqueIdTest.php`, `tests/Unit/E2eSealerTest.php`.

## Commands

```bash
php artisan serve
npm run dev
php vendor/bin/pint --dirty
php artisan test --filter=Name
```

Serve is often port 8001 if 8000 is another app. Vite is 5173. Deploy is `deploy.sh` after CI rsync. Server `.env` is not in git.
