# Build plan

Handoff file. If you're an agent picking this up: read this first, then `DESIGN.md` (visual rules),
then `readme.md`. Tick boxes as you finish things and add notes under "Log" at the bottom. Batch 1 (further down)
is done and deployed. Batch 2 is in progress. The owner said commits and deploys only happen when they ask.

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

## Batch 2 (2026-10-07): retention, discovery, social, safety, ops

The owner asked for all of these (changelog page left out on purpose).

Already existed before batch 2, so don't rebuild them: Turnstile on sign-up and forgot password (prod has keys
and `CONFIG_CaptchaEnabled=true`), the word filter on comments (comments use `chat::filter`), DM typing and "Seen",
and per-minute limits on comments and chat.

### Decisions

- **Scores** come from games through `postMessage`. Games include `/assets/js/watr-sdk.js` and call
  `watr.submitScore(n)`. play.js only accepts messages from the game's own iframe and posts them to
  `POST /api/v1/play/{id}/score`. Admins turn scores on per game (`games.scores` = off|high|low, plus a label and
  a format of number|time). Scores can be faked by anyone with devtools. The mitigations: the game has to be open
  for you right now (`users.playing_game_id`), at most 1 score every 5s per game, and an optional per-game
  `score_max`. Admins can wipe a person's scores. Boards are all time, this week (ISO week), and friends.
  `game_scores` keeps the best per (game, user, period), where period is `all` or a week like `2026-41`.
- **Challenges**: from the leaderboard, send a friend "beat my 1,234 in Slope" (notification `challenge`). If they
  beat it, the challenger gets `challenge_beaten`. Stored in the `challenges` table.
- **Game of the day**: picked by date from games with plays, the same for everyone that day
  (`ORDER BY CRC32(CONCAT(id, day))`), and it never repeats within 30 days if there are enough games. Admins can
  pin a game for today (`settings.gotd_pin` = "Y-m-d:id"). **Random**: `/games/random` (and `?unplayed=1`).
- **Streaks/XP**: `user_game_daily` (userid, gameid, day, seconds) is filled by `playtime::beat`. A day counts
  once you have 60s of playtime on it. Stored on users: `streak`, `streak_best`, `streak_day`, `xp`, `level`.
  XP = minutes played + 50 per achievement + 10 per streak day. Level = floor(sqrt(xp / 25)) + 1. New
  achievements: streak_7 and streak_30, plus top_score (#1 on a board). The level badge shows next to usernames
  on comments, profiles, leaderboards and the chat friends list.
- **Weekly recap**: `recap::build(user, week)` from user_game_daily. Delivered once per ISO week per user
  (`users.recap_week`). Lazily on the first page view after Monday (a notification), and by `bin/cron.php` (the
  notification plus an email for people who opted in, `users.recap_email`, default off). `/recap` shows last week.
- **Collections**: like playlists but for games/apps. Public or private, max 30 per user, 200 games each.
  Admin-made collections can be marked `staff` (shown as "Staff picks" on /games and /home). `/collections`,
  `/collections/{id}`, plus "Add to collection" on the play page and public ones on profiles.
- **Game details**: `games.controls` (text), `games.screenshots` (json list of upload paths, max 6), and a "New"
  badge on tiles for 14 days after `games.created`.
- **Share previews**: base.twig gets og:url/og:image/twitter:card blocks. A twig global `siteUrl` comes from
  `APP_DOMAIN` (prod is games.watr.lol). Games use their icon, profiles their avatar, the rest the 512 icon.
- **sitemap.xml/robots.txt**: routes, not files, so they use siteUrl. Static pages, games, apps, public
  collections. Not profiles (kids' usernames don't need indexing).
- **Reactions**: a fixed set of 8 emoji (no free text). One table, `reactions` (kind dm|group|comment, item_id,
  userid, emoji). Pushed live for chat.
- **Edit/delete your own**: chat messages (DM + group) can be edited or deleted for 1 hour after sending,
  comments any time. `edited` (timestamp) column. Old text goes to `message_edits` so moderators see what a
  reported message said before. Self-deleted chat messages use `deleted = 2` ("Message deleted"); 1 still means
  removed by a moderator.
- **Groups**: typing goes through node with the member list, and clients ignore typing from non-members. "Seen
  by" comes from chat_group_members.last_read, and group_read is now published to every member.
- **Rate limits**: `watrkit/ratelimit.php`, a fixed-window counter in the `rate_limits` table, keyed by
  "action:ip" or "action:u12". Over the limit returns a 429 with Retry-After. Login: 10 failures/15min per IP,
  plus 5/15min per username. Sign-in asks for Turnstile after 3 failures from an IP.
- **Mutes/bans**: users get `banned_until`, `ban_reason`, `muted_until`, `mute_reason`. A ban with a passed
  `banned_until` lifts itself. Muted people can't chat, comment, react, make groups/collections or edit. The
  `moderation_log` table holds the history, shown on the admin user page. Durations: 1h/1d/3d/7d/30d/forever.
- **Health**: `GET /health` (JSON, 200/503, no details). `bin/cron.php` runs every 5 min: checks (db, realtime,
  disk, backups < 36h old, storage writable) and alerts on state changes to `ALERT_WEBHOOK` (Discord/Slack-style
  JSON) and/or `ALERT_EMAIL`. Weekly recaps and cleanup (rate_limits, play_views) run there too. The admin
  dashboard gets a System panel.
- **Deploy**: `bin/deploy.sh` runs on the server and does the documented steps (backup, clone the branch to /tmp,
  migrate, rsync with the excludes, composer install if the lock changed, clear the twig cache, restart
  watr-realtime if realtime/ changed).
- **Prod cron**: the backup cron gets installed on prod (owner said yes). bin/cron.php goes in only after the code
  is deployed.

### Checklist

- [x] migration `20261017120000_batch_two.php`
- [x] ratelimit class + applied (login/register/forgot/reset/2fa/search/friend requests/votes/favorites/reports/uploads/AI)
- [x] login captcha after 3 failures
- [x] timed bans + mutes + reasons + moderation_log + admin UI + user-facing messages
- [x] og tags, siteUrl, sitemap.xml, robots.txt
- [x] game controls + screenshots (admin + play page), "New" badge
- [x] game of the day (home, landing, games) + admin pin, /games/random + button + `g r` shortcut
- [x] scores: sdk, play.js bridge, API, leaderboard UI on play page, admin settings, wipe scores
- [x] challenges
- [x] user_game_daily, streaks, xp/levels, level badges, new achievements
- [x] weekly recap (/recap, notification, email opt-in in settings)
- [x] collections (pages, API, play page menu, profile, staff picks)
- [x] reactions (DM, group, comments)
- [x] edit/delete own messages + comments, message_edits shown in admin reports
- [x] group typing + seen by
- [x] /health, bin/cron.php, admin System panel
- [x] bin/deploy.sh
- [ ] install the backup cron on prod (**blocked**: the permission check refused editing root's crontab on prod. the script works, a manual run made the first backup at 2026-10-08 02:52 UTC. the owner adds it, see the readme)
- [x] tests, readme, .env.example
- [x] php -l, phpunit, click through in the browser at desktop + 390px

## Batch 1 decisions

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
- **/proxy**: removed in batch 1. The owner asked for it on 2026-10-08, so it's back as a real one (see the log).
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
- 2026-10-08: **batch 2 built and tested locally, not committed or deployed.** Migrations 20261017120000 (schema) and
  20261017120100 (XP backfill from playtime + achievements) applied locally. 71 tests pass (`vendor/bin/phpunit`); the
  4 deprecations come from the pixie library. Checked in headless Chrome (puppeteer-core in the scratchpad, since the Chrome
  extension wasn't connected) at 1440px and 390px with no page errors and no horizontal overflow. Clicked through:
  a test game calling watr.submitScore -> leaderboard + "new best" toast + a worse score not replacing it, challenge -> the friend's notice and notification,
  collections dialog (create + add), comment post/react/edit, DM send/react/edit/delete between two accounts, login lockout
  (429 after 5 wrong), timed ban message, mute blocking comments. Test accounts and the test game were removed afterwards.
  Found and fixed along the way: inline scripts on collection AND playlist pages ran before the deferred jQuery on a full page load.
  **To ship:** `bin/deploy.sh` on the server (it runs both migrations). Then add the crons (backup, and bin/cron.php),
  and optionally ALERT_WEBHOOK, plus MAIL_* for recap/alert emails (prod has no mail configured).
  Prod already has Turnstile keys, so sign-up/forgot have the captcha, and sign-in now asks for it after 3 wrong passwords.

- 2026-10-08: **batch 2 deployed to prod** (4b83b20, 935fd31, 8bd0656) with bin/deploy.sh through the aaPanel MCP.
  The first run stopped at the migration: prod is MySQL 8.0, which refuses a primary key column that isn't explicitly
  NOT NULL (the local database allowed it), so rate_limits failed after the earlier tables were already made. The deploy
  script stopped before copying code, as intended. The migration now checks each step before running it, so it resumed
  cleanly. **Write migrations for MySQL 8** (explicit `'null' => false` on key columns). Prod CLI PHP is 8.5, and
  bin/bootstrap.php hides vendor deprecation notices. After the deploy, health checks are all ok except email (no MAIL_*).
  The crons (backup.sh, cron.php) are still **not installed**; adding them was refused by the permission check.
- 2026-10-08: Lyricsfile support deployed (a6cc28f). The deploy's composer step failed twice on prod: aaPanel runs commands
  without HOME, and cocur/slugify's PHP constraint stops at 8.4 while prod is 8.5. The code had already been copied, so
  the lyrics API returned 500 for a few minutes until symfony/yaml was installed by hand with --ignore-platform-req=php.
  bin/deploy.sh now sets HOME itself, passes --ignore-platform-req=php, and runs composer **before** migrations and
  code, so a composer failure leaves the old site running.
- 2026-10-08: Bloop (the pixel mascot from bloop-mascot.html) deployed (73edd66). He lives in public/assets/js/bloop.js
  and is on the ai page header, every answer, the new chat screen and the guest/disabled pages; the loading dots are
  replaced by him pondering, typing, using tools or writing. Checked in headless Chrome with a stubbed stream (the
  extension wasn't connected); note headless --virtual-time-budget only runs a handful of animation frames.
- 2026-10-08: plans + partial artifact edits deployed (4c0cdda). The model writes a <plan> checklist before building, and
  changes artifacts with mode="edit" SEARCH/REPLACE blocks (artifacts::applyEdits, mirrored in ai.js for the live
  preview). Misses change nothing and go back to the model as a hidden user message (block "auto"=>true, shown as a
  "retry" notice), at most twice an answer. Not yet tried against a real model, only a stubbed stream.
- 2026-10-08: URLs moved and deployed (588f8d4): /games -> /discover, /games/{id} -> /play/{id}, /games/random ->
  /play/random, /games/request -> /discover/request. The old ones 301 with the query string (movedTo() in
  routes/web.php). Apps stay at /apps. API paths (/api/v1/games/..., /api/v1/play/...) and /admin/games didn't change.
- 2026-10-08: **web proxy at /proxy, built and tested locally, not committed or deployed.** Scramjet 1.1 + bare-mux,
  epoxy (default) or libcurl transport, wisp-js server, all in the new `proxy/` node service (port 3002), served under
  /proxy/ on the site's own domain. The proxy SW is scoped to /proxy/ so the site's /sw.js is untouched. PHP signs a
  token like realtime does (PROXY_SECRET). Two upstream bugs worked around in proxy/public/sw.js: Scramjet waits for
  the page to ack cookies set by subresources, which deadlocks while the page is parsing (wikipedia froze), and the
  wasm path must not carry `?v=` (Scramjet compares pathnames). The bare-mux SharedWorker lives under /proxy/ too, so
  the SW must not open Scramjet's IndexedDB before the page has made it. Tested headless: wikipedia 1.5s, iana 0.6s,
  youtube search, discord login, github, scratch; reddit blocks proxies. **To ship:** nginx locations + systemd unit
  from the readme, `npm ci` in proxy/, PROXY_SECRET in .env and the unit.
- 2026-10-08: **web proxy deployed** (b637841). On the server: `npm ci` in proxy/, systemd unit `watr-proxy` (User=www,
  EnvironmentFile=/root/watrgames/proxy.env, root only, like realtime), PROXY_SECRET + PROXY_INTERNAL_URL appended to
  the site's .env (backup next to it), and three nginx locations in the vhost (backups *.ai_bak). nginx 301s `/proxy`
  to `/proxy/` when a `proxy_pass` location ends in a slash, so `location = /proxy` rewrites to index.php. Cloudflare
  cached the 404s from a check made before nginx reloaded; purged those URLs. Checked: every file 200 through
  Cloudflare, a wisp websocket with a PHP-minted token upgrades (101), /proxy sends signed out people to sign in.
  Not yet tried signed in in a real browser on prod.
- 2026-10-08: proxy fix deployed (32f5b2a). On a Chromebook /proxy failed with "One of the specified object stores was
  not found": ScramjetServiceWorker's constructor opens `$scramjet` v1 with no upgrade handler, so when the worker
  started before the page's controller.init() the database was created empty and init() could never add the tables.
  sw.js now wraps indexedDB.open to make the tables (and close on versionchange), and proxy.js deletes a database
  left without them before init(). Not reproduced locally (fast machine wins the race); confirm on the Chromebook.
- 2026-10-08: renamed URLs: /proxy -> /network (the page and everything the node service serves: /network/s/...,
  /network/sw.js, /network/~/, /network/wisp/), /play/{id} -> /item/{id}, /play/random -> /item/random. Old /proxy,
  /play/{id}, /play/random and /games/{id} 301 to the new ones. /api/v1/play/... is unchanged. The games list's tab
  title and sidebar label are "Discover", and the game "Play" buttons say "Open". The vhost's /proxy locations were
  renamed to /network.
- 2026-10-08: **sign in loop on a school Chromebook fixed and deployed** (f884a6d). The browser held a leftover
  WATR-AUTH (not a session in the db, most likely from when APP_DOMAIN was different, so another Domain) and sent it
  before the new one; $_COOKIE keeps only the first, so every page after login read the dead cookie. Every login
  made a valid session that was never used (last_used == created). sessions::pickCookie() (called in init.php) picks
  the value that's a real session when there are several and expires the strays (host only and parent domains).
  Found with a temporary nginx log, /www/wwwlogs/watr-cookiecheck.log (map at the top of the vhost, school IPs
  207.191.188.* only, logs the first 6 characters of each WATR-AUTH): **remove it once this is confirmed.**
- 2026-10-08: proxied addresses are base64url (d2ae868), a Scramjet `codec` in proxy.js. Old readable ones decode.
- 2026-10-08: the word "proxy" is gone from everything the browser gets: label/title "Network", ids and classes
  `network*`, files public/assets/js/network.js, public/assets/css/network.css, views/network.twig, and the text in
  sw.js / boot.html. Server-side names (proxy/ service, watrlabs\proxy, PROXY_* env, routes/proxy.php) stay.
- 2026-10-08: the network page's library files have neutral paths: /network/s/core.js, sync.js, engine.wasm
  (Scramjet), link.js, link-worker.js (bare-mux), fast.mjs (epoxy), compat.mjs (libcurl). The connection setting is
  "fast" / "compat" (a saved "libcurl" becomes "compat"). The files' contents still name the libraries.
