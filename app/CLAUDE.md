# Backend (`app/`)

Laravel 12 JSON API. Controllers validate and delegate; behavior lives in `Services/` and `Support/`.

## Request path

- Workspace routes sit under `workspaces/{workspace}` with the `workspace` middleware (`EnsureWorkspaceMember`). It resolves the slug, rejects non-members, and puts `workspace` and `membership` on the request.
- Controllers extend `ApiController`. Use `$this->workspace($request)`, `$this->membership($request)`, `requireManager()` (owner or admin), and `requireOwner()`. Do not re-query membership.
- Channel visibility goes through `ChannelAccess::assertCanView`. Call it on every path that reads or writes a channel, including attachments and calls.
- New write routes get a named rate limiter from `AppServiceProvider` (`messages`, `uploads`, `search`, `exports`, `presence`, `calls`, `attachments`, `login`). They are keyed by user, and by IP only for login.

## Messages

- User posts and edits go through `MessageWriter::postSealed` and `editSealed`. The server checks the blob with `E2eSealer::assertMessageBlob` and never decrypts it.
- `MessageWriter::post` is for server-written posts only (welcome, seed, attendance checkout). It stores `cipher=server` with Laravel Crypt.
- `MessageWriter::edit` is the old plaintext edit. No route calls it. Do not wire it back up.
- All message JSON comes from `MessagePresenter::present`. For `e2e` rows it returns `sealed` and leaves `text`, `document`, and `excerpt` empty. Do not add a field that leaks user text.
- Delete clears `ciphertext` and sets `deleted_at`; the row stays so threads keep their shape.
- Push and mail run in `defer()` after the response. Nothing is queued.

## Ids in URLs

- `OpaqueId::encode` / `decode` give the stable `v1.` token. Anything that reaches the address bar or a notification link uses it. `decode` returns null for a raw number, so a numeric id never resolves.
- Attachments are fetched by opaque ref too; a numeric attachment id is a 404 by design.

## Crypto formats

`E2eSealer` documents the wire formats in its class comment (wrap, backup, message, HKDF info strings, AAD). `resources/js/e2e.js` implements the same formats in the browser. Change both or neither, and bump the version byte rather than altering `0x01` in place.

## Realtime and calls

- `AblyPublisher` is the only place that talks to Ably. Channel names come from its helpers (`messageChannel`, `shiftChannel`, `workspaceEventsChannel`, `typingChannel`); do not build the strings elsewhere.
- Token capability is scoped to one workspace. `SecurityTest` asserts this; keep it passing when touching `capability()`.
- A missing key means `publish` does nothing and the client polls. Do not treat that as an error.
- `CallService::signal` restores the trailing CRLF on SDP that `TrimStrings` removed. Leave that guard in.

## Attendance

`AttendanceService::punch` owns the state machine and the presence check (office IPs, optional GPS radius). Totals come from `AttendanceCalculator`; working-day and holiday rules from `ScheduleInspector`. Timestamps are stored in app time; the work date and schedule are worked out in the workspace timezone. `Carbon::timezone()` mutates, so copy before converting a value that will be saved.

## Migrations

Add a new migration; do not edit a shipped one. It must run on MySQL 8 and on the SQLite used by tests.
