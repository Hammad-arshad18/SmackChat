# SmackChat

Office chat for a company workspace. People sign in with their company account, talk in channels and private direct messages, and the owner’s floor clock turns checkout into a timesheet.

Laravel runs the API and the Vue app. MySQL stores the workspace. Ably carries live messages when `ABLY_KEY` is set. Without that key the app keeps working and refreshes the open conversation every few seconds.

## What it does

- Workspaces, invites, and roles: owner, admin, member
- Public channels, private channels, and direct messages that only the two people can open
- Threads, reactions, inline editing, and safe copy/delete actions on a message
- A rich composer: bold, italic, underline, strike, lists, quote, code, links, emoji, @mentions, and per-conversation drafts
- Messages you write are end-to-end encrypted in the browser before they are sent. Server posts, such as the welcome message and attendance checkout, stay readable by the server. Attachments are stored on the server and are not end to end
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

## Requirements

Docker is enough to run the app. To work on it outside containers you also need PHP 8.2 or newer, Composer, and Node.js 22.

## Run it with Docker

```bash
docker compose up -d --build
```

Open http://localhost:8001.

The first start installs PHP dependencies, builds the frontend, migrates the database, and seeds Northwind Studio. That can take a few minutes. Follow it with:

```bash
docker compose logs -f app
```

Compose sets the app’s database settings itself. MySQL inside the stack is `mysql:3306`, user `smackchat`, password `secret`, database `smackchat`. The same server is published on the machine at `127.0.0.1:3307`, so it can sit beside a MySQL that already uses port 3306.

An existing `.env` is left in place. The container does not use that file’s `DB_HOST` for the web server. If `.env` is missing, the container copies `.env.example` and generates `APP_KEY`.

Stop the stack with `docker compose down`. Add `-v` only when you also want to delete the database volume.

## Run it on the machine

Start only the database, then run PHP and Vite yourself:

```bash
docker compose up -d mysql
cp .env.example .env
php artisan key:generate
composer install
npm install
php artisan migrate --seed
npm run dev
php artisan serve
```

`.env.example` points at the published database on port 3307. `php artisan serve` listens on http://localhost:8000. If that port is already taken, serve on 8001 and set `APP_URL` and `SANCTUM_STATEFUL_DOMAINS` to match.

`composer run dev` starts the web server, queue worker, log tail, and Vite together.

## Sign in

Either way of running the app seeds **Northwind Studio**.

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
