# Tests (`tests/`)

PHPUnit on in-memory SQLite. Feature tests use `RefreshDatabase`.

```bash
php artisan test --filter=test_name_or_class
```

## Where a test goes

- `Feature/OfficeChatTest.php`: chat, attachments, attendance, schedule, people, plans.
- `Feature/CallsTest.php`: huddles and signaling.
- `Feature/SecurityTest.php`: proxy spoofing, login lockout, password reset, Ably capability, audit, export, deletion, backup, health.
- `Unit/`: pure classes (`AttendanceCalculator`, `E2eSealer`, `OpaqueId`, `OfficeNetwork`).

Add to the file that already covers the area. Name tests as a sentence: `test_only_sender_or_admin_can_delete_a_message`.

## Chat posts

The API only accepts sealed messages, so a test cannot post text directly. Use `Tests\Support\E2eParty`:

- `E2eParty::register($this)` registers a device for the acting user.
- `->send($this, $workspace, $channel, $text, $extra)` seals and posts.
- `->plain($this, $workspace, $channel, $message)` opens a returned message.
- `->shareMissing(...)` hands the channel key to devices that lack it.
- `installVault`, `unlockVault`, `backupChannel`, `claimFromVault` cover the key backup flow.

A second person needs their own `E2eParty` and the key shared to them before they can read.

## What to assert

- User plaintext is absent from the response JSON and from the `messages` table.
- Thread, focus, and notification links use `OpaqueId::encode($id)`, never a raw id.
- A person outside a private channel or DM gets 403, including on attachments and calls.
- Role limits: a member is refused where owner or admin is required.

## Base class behavior

`Tests\TestCase` turns off Vite and runs deferred work inline, so push and mail effects are visible to assertions straight after the request. Mail uses the `array` mailer and the cache is `array`, so rate limiters reset between tests.
