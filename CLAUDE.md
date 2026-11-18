# SmackChat

The shared agent docs are the source of truth. They are imported here so they are not copied and left to drift:

@AGENTS.md
@Rules.md

`Memory.md` holds longer background on the same topics (seed people, key handling, huddles). Read it when a task touches those. `PRODUCTION.md` is the launch checklist. `README.md` is stale on encryption; do not use it for behavior.

When a rule changes, edit `AGENTS.md` or `Rules.md`, not this file. Keep this file to what only Claude Code needs.

## Area guides

Each loads when you work in that directory:

- `app/CLAUDE.md`: API, services, crypto, realtime
- `resources/js/CLAUDE.md`: Vue SPA, stores, composer, client crypto
- `tests/CLAUDE.md`: how chat tests are written

## Checks before calling a change done

```bash
php vendor/bin/pint --dirty
php artisan test --filter=NameOfTheTestYouTouched
npm run build
```

- Run `npm run build` only when JS, Vue, or CSS changed.
- Tests use in-memory SQLite. CI also migrates and seeds on MySQL 8, so a migration must work on both.
- A UI change is checked in the browser at a desktop width and under 1080px, by clicking through it, not by a screenshot alone.
- Sign in with the seed accounts in `AGENTS.md`. They exist only after `php artisan migrate --seed`.

## Things that are easy to get wrong

- A push to `main` deploys to production once CI is green. Work on a branch and do not push `main` unless asked.
- After changing JS dependencies, regenerate the lockfile the way `PRODUCTION.md` describes, or the Linux build in CI breaks.
- `.env` is real and local. Do not edit it, print it, or copy values out of it.
