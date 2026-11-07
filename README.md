# SmackChat

Office chat for a company workspace. People sign in with their company account, talk in channels and private direct messages, and the owner’s floor clock turns checkout into a timesheet.

Laravel runs the API and the Vue app. MySQL stores the workspace. Ably carries live messages when `ABLY_KEY` is set. Without that key the app keeps working and refreshes the open conversation every few seconds.

## What it does

- Workspaces, invites, and roles: owner, admin, member
- Public channels, private channels, and direct messages that only the two people can open
- Threads, reactions, inline editing, and safe copy/delete actions on a message
- A rich composer: bold, italic, underline, strike, lists, quote, code, links, emoji, @mentions, and per-conversation drafts
- Message text is encrypted at rest with the application key (AES-256-CBC)
- A Today dashboard with shift status, live timer, unread catch-up, team presence, weekly hours, and next office day
- A ⌘K command palette to jump between conversations, people, actions, and full-text search results
- Full-workspace message search (search respects channel membership and the history window)
- Floor clock: working days, opening hours, timezone, and holidays
- Check in, start break, end break, and check out
- Checkout writes the day’s hours into the private Timesheets channel. The owner is in it from the start and can add other people
- Attendance overview with a monthly heatmap, team summary, shift ledger, and CSV export for the owner or an admin
- Schedule editor with a live preview, holiday calendar, timesheet readers, and workspace identity
- People directory with presence, status messages, role changes, and invite management
- Light, dark, and system themes; compact density; message sounds; optional desktop notifications
- Plenty of PrimeVue components: CommandMenu, DataTable, Tabs, Drawer, Dialog, Popover, Menu, Knob, MeterGroup, Accordion, SelectButton, ToggleSwitch, DatePicker, Password, Toast, and more
- History is kept for 90 days. The owner can turn on the forever archive at $7 per person each month

## Run it

Start MySQL:

```bash
docker compose up -d
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
npm install
npm run dev
php artisan serve
```

Open http://localhost:8000.

Seeded company: **Northwind Studio**

| Person | Email | Password | Role |
| --- | --- | --- | --- |
| Ada Lovelace | ada@northwind.test | password | Owner |
| Lin Zhao | lin@northwind.test | password | Admin |
| Grace Hopper | grace@northwind.test | password | Member |

Ada is already checked in. Grace’s Friday shift, including a break, is in Timesheets.

Put an Ably API key in `ABLY_KEY` for instant delivery and typing indicators. The key stays on the server. Browsers receive a short-lived token that can only subscribe to their workspace.

A daily command deletes standard-plan messages older than 90 days:

```bash
php artisan smackchat:prune-history
```

Schedule it with `php artisan schedule:work` or the system cron entry `php artisan schedule:run`.

The forever archive is recorded in the workspace. It is not a card charge. Connect a payment provider before billing a real company.
