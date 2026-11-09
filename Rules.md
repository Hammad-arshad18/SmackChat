# Rules

SmackChat is Laravel 12 + Vue 3 + PrimeVue office chat: channels, DMs, threads, attendance, schedule, people, and plans. Change only the task. Match nearby code. Do not commit, push, or edit `.env` unless asked.

## Every change

- PHP: `php vendor/bin/pint --dirty`, then `php artisan test --filter=` the test you touched.
- UI (layout, routing, client state, rendered data): exercise it in the browser. Click, type, submit. A screenshot is not enough. If chat layout changed, check a phone width and a desktop width.
- Do not print the app key, Ably key, VAPID private key, or channel keys.
- Do not trust `README.md` for encryption. User messages are end to end.

## Auth and workspace

- Register is email-code then workspace create. Login is Sanctum.
- Roles are owner, admin, member. Billing and destructive admin actions stay behind the existing role checks.
- Invites are single-use tokens. Do not put the token in a log.
- New members join public channels only. Private, DM, and Timesheets membership is explicit.

## Channels and messages

- Types: `public`, `private`, `dm`, `attendance`. DMs use `dm_key`. Do not let a third person read a DM.
- User create/update sends `sealed`. Never accept a plaintext `document` again.
- Leave `cipher=server` for welcome, seed, and checkout posts. The server has no channel key then.
- Deleted messages clear ciphertext. Presenter shows the deleted copy, not the old text.
- Attachments stay normal file uploads. Do not claim they are end to end.
- Drafts stay in `localStorage` under `smack.draft.`. Do not send draft text to the server.

## Threads, reactions, URLs

- Desktop thread column includes its own reply field. The channel composer still posts to the channel.
- Under 1080px the thread covers the screen (`position: fixed; inset: 0`) and the channel composer is hidden. Do not leave a narrow side column on a phone.
- `thread`, `m`, and `reply` query values are `OpaqueId` `v1.` tokens. Never write a numeric id into the URL. Drop numeric values.
- Refresh on `?thread=` must reopen that thread. Closing the thread clears the query.
- Reactions publish `message.reacted` and `actor_id`. Preserve the viewer's `mine` flag when the actor is someone else.
- Live event name is `message` on `smackchat:ws:{workspaceId}:ch:{channelId}`. Missing Ably is a poll, not a bug.

## Attendance, people, plans

- Punches: `check_in`, `break_in`, `break_out`, `check_out`. Checkout posts hours into Timesheets.
- Schedule edits go through `ScheduleController`. Default week is Mon–Fri 09:00–17:00 in the workspace timezone.
- Standard plan prunes history (`smackchat:prune-history`). Archive plan keeps it. Do not wire a fake card charge; billing only flips `workspaces.plan`.
- Search for `e2e` rows is on the device. Do not send the search phrase to the server.

## Tests

- Chat posts in tests go through `tests/Support/E2eParty.php`.
- Assert user plaintext is absent from the JSON and the database.
- Focus/deep links in tests use `OpaqueId::encode`, not a raw id.
