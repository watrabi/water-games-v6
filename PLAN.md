# Build plan: the big feature batch

Handoff file. If you're an agent picking this up: read this first, then `DESIGN.md` (visual rules),
then `readme.md`. Tick boxes as you finish things and add notes under "Log" at the bottom. Everything below is
built and tested locally but not committed or deployed yet. The owner asked for **all** of these, and said commits only happen when they ask.

## How the codebase works (short)

- PHP 8.3, no framework. `public/index.php` → `init.php` (globals `$db` = Pixie query builder,
  `$twig`, `$currentuser`) → route files in `routes/` (`web`, `authapi`, `ai`, `admin`, `social`), added
  to the `$routers` list in `public/index.php`. A route returning an array is sent as JSON.
- Classes autoload from `classes/` by namespace (`watrlabs\games\games` → `classes/watrlabs/games/games.php`).
- Views are Twig in `views/`, all extend `views/models/base.twig`. Shared macros: `components/games.twig`
  (tile, grid, empty), `components/avatar.twig`. Admin pages extend `views/admin/base.twig` and use
  `adminRender()` / `adminRedirect()` / `requireAdminPost()` (csrf) from `routes/admin.php`.
- JS is jQuery plus plain functions. **Pages load in place** (`watrgames.js` swaps `#main`), so page scripts
  are IIFEs that run again on each visit. Use `watr:leave` / `watr:load` document events to clean up. Bump the
  `?t=` on a script or stylesheet when you change it. Changing `base.css?t=` forces a full reload on the next nav.
- Realtime: `realtime/server.js` (node ws). PHP calls `\watrlabs\social\realtime::publish([userIds], event)`.
  `chat.js` holds the only socket; it should re-dispatch non-chat events as
  `document.dispatchEvent(new CustomEvent("watr:live", {detail: data}))` so other scripts (notifications, presence) can listen.
- Migrations: phinx, `vendor/bin/phinx migrate`. Local dev DB: mysql `watrgames`/`watrgames`/`watrgames`.
  Run locally: `php -S localhost:8000 -t public dev-router.php`.
- Style rules (DESIGN.md): color tokens only, square corners, no gradients/glow, Archivo for headings, blob
  emoji for empty states, phosphor icons (`ph-bold ph-...`). Copy is casual and lowercase-ish, chrome stays plain.

## Decisions already made

- **Playtime**: counts only while `document.visibilityState === "visible"` AND `document.hasFocus()`.
  Poll every 1s (clicking into the game iframe fires `blur` on the parent window, but `hasFocus()` stays true,
  so don't rely on blur/focus events alone). Heartbeat `POST /api/v1/play/beat` every ~15s and on
  unfocus/leave (`sendBeacon`). The server credits `min(claimed, 60, now - users.play_beat + 2)`; that rule
  lives in `playtime::credit()`. `play_beat` is per user, so two open tabs can't double count.
- **Cloud saves**: games in `/game-files/` are same-origin, so they share the site's localStorage. While a
  game is open, the parent page listens for `storage` events (they fire for changes made in the iframe) to learn
  the game's keys, then uploads `{key: value}` for those keys (debounced, plus on leave). On page load, signed
  in: fetch the save, write the keys into localStorage, *then* set the iframe `src` (it becomes `data-src`).
  Limit 1MB per game. Skip the site's own keys (`sidebarClosed`, `aiModel`, music/lyrics prefs, `wg*`).
  Cross-origin games and IndexedDB-only games aren't supported; say so in the UI.
- **/proxy**: remove the placeholder (route + sidebar). Building a web proxy is out of scope and an abuse risk.
- **Group chats**: separate tables (`chat_groups`, `chat_group_members`, `chat_group_messages`), not a
  rework of the 1:1 `chat_messages`. Only friends can be added; max 20 members. Reports on group messages
  go into `chat_reports` with `kind = 'group'`.
- **Notifications**: a `notifications` row per user. The text is built at display time in
  `notifications::describe()`. Types: friend_request, friend_accept, mention (@username in a comment),
  achievement, request_added, request_declined, group_added, announcement (admin broadcast).
- **Privacy**: `users.share_activity` (default on) hides "Playing X" and the activity feed from friends.
- **2FA**: TOTP (RFC 6238, own implementation in `classes/watrlabs/authentication/totp.php`), secret stored
  encrypted with the existing `encryption` class, 8 recovery codes (hashed), `totp_last_step` blocks reuse. Login
  returns `{status:"2fa", token}` and then `POST /api/v1/auth/2fa`. QR: `qrcode-generator` from cdnjs.
- **Password reset**: PHPMailer (already in composer). New env: `MAIL_HOST, MAIL_PORT, MAIL_USER, MAIL_PASS,
  MAIL_FROM, MAIL_SECURE`. If mail isn't configured and `APP_DEBUG=true`, write the link to
  `storage/logs/mail.log`. Tokens are sha256-hashed and last 1 hour. Respond the same whether or not the
  account exists.
- **Backups**: write `bin/backup.php` (mysqldump | gzip into `storage/backups`, keep 14) and document the
  cron line. **Don't** install anything on the server without asking the owner. aaPanel tools exist, but that's prod.

## Migration

`db/migrations/20261015120000_play_social_accounts.php`: DONE and applied locally. Adds:
users (totp_*, recovery_codes, playing_game_id, playing_since, play_beat, share_activity), sessions (created,
last_used, user_agent), password_resets, login_challenges, games.created, tags (seeded 10), game_tags, game_votes,
playtime, game_daily, cloud_saves, game_requests, game_reports, notifications, user_achievements, activity,
chat_groups, chat_group_members, chat_group_messages, chat_reports.kind, playlists, playlist_tracks, admin_log.

## Checklist

Backend classes (`classes/watrlabs/...`):
- [x] `games/playtime.php`: credit rule, start/beat/stop, recent, most, total, presence, format
- [x] `social/notifications.php`: send, broadcast, list, unread, markRead, prune
- [x] `social/achievements.php`: definitions + checkPlaytime/check(user, event) → notify + activity
- [x] `social/activity.php`: log(), feedFor(user) (friends who share_activity + new games)
- [x] `games/cloudsaves.php`: get/put with a size limit, key filtering
- [x] `games/tags.php`: all, forGame, setForGame; games::list gets `?tag=`
- [x] votes + trending in `games/games.php` (trending = plays in game_daily over the last 7 days), and recordPlay → game_daily plays
- [x] `games/requests.php`: game requests + broken-game reports
- [x] `social/groups.php`: create, add/remove/leave, rename, send, history, poll, markRead
- [x] `music/playlists.php`
- [x] `authentication/totp.php`, `authentication/passwordreset.php`, `watrkit/mail.php`
- [x] sessions.php: fill created/last_used/user_agent; list + revoke one
- [x] `users/accountdata.php`: export JSON, delete account (files too)
- [x] `watrkit/adminlog.php` + called from every admin POST
- [x] twig filter `playtime` (seconds → "3h 12m") in `app/twig/siteHelper.php`

Routes / pages:
- [x] play page: playtime tracker JS (`public/assets/js/playtime.js`), "You've played X", vote buttons, tags,
      cloud save status, report-broken button + modal, theater mode, `F` for fullscreen
- [x] `/home`: continue playing (playtime::recent), friends' activity, friends playing now
- [x] `/games`: tag chips (`?tag=`), Trending sort
- [x] `/games/request`: form + your past requests
- [x] notifications: bell in `components/header.twig`, dropdown, `/notifications` page, API under `/api/v1/notifications`
- [x] presence: friends list shows "Playing X" (friends::list adds `playing`), chat.js renders it, listens for `presence`
- [x] group chats in the tray (chat.js + chattray.twig + social routes + poll includes groups)
- [x] achievements on profiles (`#achievements`), playtime stats on profiles (total, most played)
- [x] music: playlists page(s), add to playlist, queue / play next / up next in music.js
- [x] auth: forgot password page + reset page, 2FA step on sign-in
- [x] settings: email, 2FA setup, active sessions, privacy (share_activity), cloud saves list, export, delete account
- [x] admin: stats charts (signups/day, plays + playtime/day, top by playtime, AI messages by provider),
      requests page, broken-game reports (on the Reports page), tags on the game form + a tags editor, audit log page, broadcast notification
- [x] PWA: `public/manifest.webmanifest`, `public/sw.js` (static assets + an offline page only, never cache HTML
      pages or the API), icons, register in watrgames.js
- [x] keyboard shortcuts: `/` search, `?` help dialog, `Esc` closes things, `F` fullscreen on the game page
- [x] mobile pass (check at 375px with the browser)
- [x] remove /proxy
- [x] `bin/backup.php` + readme section
- [x] tests: `phpunit.xml`, `tests/` (totp, playtime::credit, playtime::format, cloud save filtering, notification
      describe, password validation). Pure functions first; DB tests only if a test DB is easy to set up
- [x] readme: document every new feature + the new env vars (also add them to `.env.example`)
- [x] final: php -l every file, click through every page in the browser, check the console for errors

## Log

- 2026-10-07: read the codebase, wrote and applied the migration, wrote playtime.php and notifications.php.
- 2026-10-07: all backend classes written. Routes: `routes/play.php` (playtime beat/stop, cloud save, vote,
  broken report, game requests), `routes/account.php` (2FA, sessions, email, privacy, export, delete,
  forgot/reset), `routes/playlists.php`; groups + notifications API added to `routes/social.php` (group report is
  `POST /api/v1/social/group-report/{id}` so it doesn't collide with `/groups/{id}/{action}`). web.php now passes
  the new data to home/games/play/profile/settings and has /auth/forgot, /auth/reset, /notifications, /offline.
  **Views and JS for all of these are NOT written yet.** Twig will error on missing templates: game-request.twig,
  auth/forgot.twig, auth/reset.twig, notifications.twig, offline.twig, playlists.twig, playlist.twig.
  Admin reports page still assumes every chat_report is a DM; it needs a `kind` filter (dm vs group).
- 2026-10-08: **Prod bug, audio upload 500 on games.watr.lol.** Cause: aaPanel's PHP 8.5 has no `fileinfo`
  extension (`class_exists("finfo")` is false), and `uploads::store()` did `new \finfo`. Fixed in the repo:
  `uploads::audioType()` falls back to reading magic bytes (`uploads::sniffAudio()`). Not deployed yet. The
  owner can also install fileinfo from aaPanel (App Store → PHP 8.5 → Install extensions). Also seen in the
  prod logs: favicon.ico 404s (the PWA icons fix this), and a missing user_themes table earlier that's
  migrated now.
- 2026-10-08: all views written (home, games, play, profile, settings, auth forgot/reset/2fa, notifications,
  game-request, playlists, playlist, offline, admin dashboard charts/requests/tags/log/reports kinds/broadcast).
  `public/assets/css/features.css` + admin.css additions. chat.js has groups + presence + `watr:live` and
  `watr:poll` events. music.js has a queue (playNext, addToQueue, Up next panel). STILL TODO: shortcuts.js,
  PWA (manifest, sw.js, icons), bin/backup.php, tests, readme/.env.example, then run everything in the browser.
- 2026-10-08: **batch done.** 38 tests pass (`vendor/bin/phpunit`). Checked in headless Chrome at 1440px and 390px:
  every page, the bell, the chat tray + groups, 2FA QR, admin dashboard. Playtime verified in a real browser: counts
  while focused (including focus inside the game iframe), stops when unfocused, final beacon on leave clears
  "Playing". Cloud save verified through a real storage event. Fixed along the way: totp_secret column too short
  (now 255), init.php autoloader used a relative path that broke the error page during shutdown (also in prod logs),
  settings tabs offset, dashboard AI provider names.
  **To ship:** deploy the code, `vendor/bin/phinx migrate` on the server, add MAIL_* to the server's .env for reset
  emails, optionally add the bin/backup.sh cron. fileinfo is already installed on prod PHP 8.5.
- 2026-10-08: **deployed to prod** (96df1ca, then 2584a3e/54a1dbe). Deploys are manual: back up to
  /www/backup/site/, `git clone --branch claude/keen-fermat-93ac9e` to /tmp, run the new migration first, then
  rsync code excluding .env, phinx.php, vendor/, storage/, public/uploads/, public/game-files/ (prod has games that
  aren't in the repo). Prod is behind a Cloudflare tunnel: nginx sets the real visitor address from
  CF-Connecting-IP for requests from 127.0.0.1, so PHP's REMOTE_ADDR is already the visitor.
- 2026-10-08: play spam fix. `watrkit/playcounter.php` + `play_views` table: one counted play per person per game
  per 30 min (songs 10 min), accounts by id, guests by HMAC of IP. Existing inflated counts were left alone
  (owner's call); admins can reset a game's count from its edit page.
