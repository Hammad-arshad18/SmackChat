# Memory

Facts about the whole app. Easy to undo if you only remember encryption.

## What the app is

Office chat plus a floor clock. One workspace per company. People talk in channels and DMs. Checkout writes a timesheet. Owner and admin see attendance. History is 90 days unless the owner turns on the forever archive.

Stack: Laravel 12, Vue 3, Vite, PrimeVue, MySQL, Sanctum, Ably, Web Push. SPA is served by Laravel (`routes/web.php` → `resources/views/app.blade.php`). API is `routes/api.php`.

## People

Seeded Northwind Studio, Asia/Dubai, password `password`:

- Ada Lovelace, `ada@northwind.test`, owner, already checked in for the seed day
- Lin Zhao, `lin@northwind.test`, admin, design
- Grace Hopper, `grace@northwind.test`, member, Friday shift with a break is in Timesheets

Register: email code, then `WorkspaceProvisioner::create`. That makes `#general`, `#timesheets`, and a server-written welcome. `admit` adds the person to public channels. Roles: owner, admin, member.

## Conversations

- `#general` is public. `#design` is an extra public channel in the seed. `#timesheets` is type `attendance` and private to people on that channel.
- DMs are type `dm`, slug like `dm-ada-grace`, `dm_key` is the sorted user ids.
- Composer features: marks, lists, quote, code, links, emoji, mentions, files, per-conversation drafts (`smack.draft.` in `localStorage`).
- Messages can be edited, deleted, copied, reacted to, and threaded. `reply_count` and `last_reply_at` live on the parent.
- System attendance lines use `system_type=attendance`.

## Encryption and search

- User text is end to end. Column `messages.cipher`: `e2e` or `server`.
- `e2e`: browser seals the TipTap JSON. API field is `sealed`. Presenter returns empty `text` and null `document`. Server cannot read it.
- `server`: Laravel Crypt. Welcome, seed, and checkout posts only.
- Device key is P-256 in `localStorage` `smackchat.e2e.v1.{userId}`. Public key in `e2e_devices`. Channel AES key wrapped per device in `channel_key_wraps`. Migration `2026_11_09_090000_add_end_to_end_message_keys.php`.
- A new browser learns the channel key only when someone who already has it opens the workspace. Clearing site data loses the key.
- Search does not upload the phrase. Client filters decrypted recent rows. Membership still limits which channels are returned.
- Push body for user text is `New message`. Attachments are readable by the server.

## URLs and threads

- `OpaqueId` is AES-256-GCM, stable `v1.` token. `ref` / `parent_ref` are on every presented message. Raw numbers do not decode.
- Open thread: `?thread=<parent ref>`. Refresh restores it. Back closes it. Close removes the query.
- Notification and search links use `?m=<parent ref>&reply=<reply ref>`, then opening the thread collapses that to `thread`.
- Desktop: thread panel is column 2, its composer aligns with the channel composer. Phone and tablet (≤1080px): thread is a full-screen sheet.

## Live and notifications

- Ably: `smackchat:ws:{workspaceId}:ch:{channelId}`, event name `message`. Reactions use `event: message.reacted` plus `actor_id` so other clients do not mark the reaction as theirs.
- No Ably key: poll the open conversation. Still a working app.
- Web Push needs VAPID. iOS only notifies from the installed home-screen icon. The green banner says that.

## Time and money

- Default schedule Mon–Fri 09:00–17:00, workspace timezone. Holidays in `holidays` (`repeats_yearly`).
- Punches: check in, break in, break out, check out. Checkout posts hours into Timesheets.
- `standard` plan: prune after `SMACKCHAT_RETENTION_DAYS`. `archive` plan: keep forever, price `SMACKCHAT_ARCHIVE_PRICE_CENTS` per seat. Recorded on the workspace only. No payment provider.

## Run and ship

- MySQL via `docker compose`. DB `smackchat` / user `smackchat`.
- `php artisan serve` may be port 8001 if 8000 is taken. Vite is 5173. Do not treat another app on 8000 as SmackChat.
- Tests: `php artisan test`. Chat fixtures: `tests/Support/E2eParty.php`.
- Deploy: GitHub Actions rsync, then `deploy.sh` (`composer install`, `migrate --force`, caches). Server `.env` must already exist.
