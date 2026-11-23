# Frontend (`resources/js/`)

Vue 3 SPA with Pinia, PrimeVue, and a TipTap composer. Laravel serves one Blade view and the router takes over. `@` is an alias for `resources/js`.

## Where things live

- `router.js`: every page. Workspace pages are children of `Shell.vue` under `/w/:slug`.
- `api.js`: the axios instance (`/api`, cookies on). Use it for every request, and `errorText(error)` for the message shown to the person.
- `stores/auth.js`: the signed-in user. `stores/office.js`: workspace, channels, unread, shift, and the realtime bindings. `stores/calls.js`: huddles and WebRTC. `stores/ui.js`: theme, density, reduced motion, sound and notification preferences, and whether the command palette is open.
- `realtime.js`: the Ably client. `bindOffice` is called by the office store; views do not subscribe on their own.
- `e2e.js`: device key, channel keys, seal, reveal, search.
- `time.js`: all date, duration, and money formatting. Reuse it instead of formatting inline.
- `views/ChannelView.vue` and `components/Composer.vue` are the two largest files. Read the part you need rather than the whole file.
- `vendor/liquid-glass/` is third-party code. Its `lg-` tags are registered as custom elements in `vite.config.js`. Do not edit it.

## Messages in the browser

- Send with `sealDocument(office, channel, document)` and post the result as `sealed`. Never post a plaintext `document`.
- A message from the API or Ably is unreadable until `revealMessage(office, message)` has run on it. Any new place that shows message text must reveal first.
- Search uses `searchMessages` in `e2e.js`: the server returns recent rows and the filter runs here. The search phrase must not go into a request.
- Drafts stay in `localStorage` under `smack.draft.`. The device key is in `localStorage` (`smackchat.e2e.v1.{userId}`) and the unlocked master key in `sessionStorage` (`smackchat.e2e.master.v1.{userId}`). Do not log them or send them anywhere.

## URLs

- Use `message.ref` and `message.parent_ref` for `thread`, `m`, and `reply` query values. Numeric ids stay in memory only.
- `?thread=` must survive a refresh, and closing the thread removes it.
- `?call={id}` joins once and is then removed from the URL.

## Realtime

- Assume Ably may be absent. Anything that reacts to a live event also needs a polling path that reaches the same state: `ChannelView.vue` polls the open conversation, `stores/office.js` polls channels and the shift, `stores/calls.js` polls active calls and signals. Polling keeps running at a slower rate when Ably is connected.
- On `message.reacted`, keep the viewer's own `mine` flag unless `actor_id` is the viewer.

## Layout

- All styles are in `resources/css/app.css`. Find the existing block for a component and extend it; match the tokens already used there.
- Under `max-width: 1080px` the thread is a full-screen sheet and the channel composer is hidden. Check both widths after any chat layout change.
- Light, dark, and system themes all need to work. `theme.js` holds the PrimeVue preset.

## On-device AI

`ai.js` runs rewrite, draft, and summarize on the device: Chrome's built-in model first, then the Transformers.js worker in `workers/ai.worker.js`. `agent.js` turns an Ask AI request into up to three of those tasks, or into search, open, unread, or a clock punch. A draft or reply is sent, and a punch runs immediately. No AI text goes to the API. Keep it that way.

## Service worker

`public/sw.js` handles push and notification clicks. A change there only reaches people after the worker updates, so test with a hard reload and an unregistered worker.
