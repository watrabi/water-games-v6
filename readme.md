# water games v6
game website  
technically already exists but the laravel site is really bad and was used for like 2 days  


# info
this is entirely written in php (at least as of right now)  
there's also a database migration system and whatnot  
to use the migration system  
install composer libraries  
edit phinx.php  
(put in your database info)  
then run `vendor/bin/phinx migrate -e development` or whatever your current environment is  


# pages
- `/` landing, `/home` (signed in), `/discover` (search with `?q=`, sort with `?sort=popular|newest|name`)
- `/play/{id}` plays a game: the iframe loads `gamePath`, the tile uses `gameIcon` (falls back to the first letter if it's empty)
- `/favorites`, `/users/{username}`, `/settings`, `/auth/logout`
- `/terms`, `/privacy`, `/credits`
- the old `/games`, `/games/{id}`, `/games/random` and `/games/request` addresses 301 to the new ones (query string kept)
- `/apps`, `/apps/{id}` same as games but for non-game stuff (rows in `games` with `type = 'app'`)
- `/discover?tag=puzzle` filters by category, `?sort=trending` is plays over the last week, `?sort=liked` is by votes
- `/music` track list, with a player bar that follows you between pages. `/music/playlists` for your playlists
- `/ai` chat with saved conversations, tools and image input (see below)
- `/discover/request` asks for a game to be added, `/notifications` is everything the bell has shown you
- `/proxy` web proxy (see below)
- `/collections`, `/recap`, `/play/random`, `/sitemap.xml`, `/robots.txt`, `/health`
- `/auth/forgot` and `/auth/reset` for forgotten passwords (needs mail, see below)

# admin panel
`/admin` (404s for everyone who isn't an admin). make your first admin in the database:
```sql
UPDATE users SET admin = 1 WHERE username = 'yourname';
```
after that you can make other people admins from the panel. it covers:
- games and apps: add / edit / delete, upload icons
- music: add / edit / delete, upload audio and covers (the length fills itself in)
- users: ban (signs them out and stops them signing in), make admin, sign out of every device
- site & themes: default theme, seasonal themes, a site-wide announcement strip
- AI: turn it on, limits, tools, and providers + models (see below)

uploads go to `public/uploads/`, which the web server needs to be able to write to.
big audio files also need `upload_max_filesize` / `post_max_size` raised in php.ini.

# playtime
the game page counts how long you play, but only seconds where the tab is the visible one **and** the window has
focus (clicking into the game still counts, since that focus is inside the page). it sends what it counted every
15 seconds, when you click away, and when you leave the page. the server never credits more than the time since your
last heartbeat (from any tab), at most 60 seconds at once, so two games side by side don't double up and a tampered
browser can't send hours in one go. the rule lives in `playtime::credit()` and has tests.

it shows up on the game page ("3h 12m played"), on `/home` ("Continue playing"), on profiles (total, most played)
and in the admin dashboard (playtime per day, most played this month). `game_daily` keeps plays and seconds per game
per day, which is also what "Trending" sorts by.

# cloud saves
games in `public/game-files/` share the site's origin, so they use the site's localStorage. while a game is open,
the page listens for `storage` events (they fire when the game's frame writes) to learn which keys are the game's, and
uploads those keys to your account. on another device the save is written back into localStorage *before* the game
loads. up to 1MB per game, merged key by key so a device that only knows some keys doesn't wipe the others. the
site's own keys (`watr*`, `wg_*`, `sidebarClosed`, `aiModel`) are never included. games hosted on other sites, and
games that save to IndexedDB (most Unity WebGL builds), can't be saved this way. people can see and delete their
saves under Settings → Privacy & saves.

# leaderboards
games can send scores to the site. switch it on for a game in admin → Games → the game → Leaderboard (higher or
lower is better, a number or a time, a label like "points", and the highest believable score). then in the game:
```html
<script src="/assets/js/watr-sdk.js"></script>
<script>
  // when a run ends. times are milliseconds
  watr.submitScore(1234).then(result => {
    if(result.personalBest){ /* show "new best!" */ }
  });
</script>
```
the game page passes it on, so this works for games on the site and on other sites. scores only count while the game
is open for that person, at most one every 5 seconds per game, never above the game's limit. **anyone with devtools can
still send a made-up score**, it's a browser game. admins can wipe someone's scores from their admin page. boards are
all time, this week (monday to sunday) and friends. from the board you can challenge a friend to beat your best; they
get a notification, and you get one if they do.

# discovering games
- **game of the day**: picked automatically each day (same for everyone, no repeats within 30 days, skips games
  people mostly dislike), shown on `/`, `/home`, `/discover` and the game itself. admins can pin one for today from the
  game's admin page
- **random**: `/play/random` (and `/play/random?unplayed=1` for one you haven't tried), `g` then `r` anywhere
- **collections**: `/collections`. anyone can make lists of games with the folder button on a game page, public or
  private. collections an admin marks as staff picks show on `/discover` and `/home`
- **game pages** can have "How to play" (controls) and up to 6 screenshots, from the admin form. games added in the
  last two weeks get a "New" badge
- **share previews**: games, profiles, playlists and collections have their own title, description and picture when
  a link is pasted into Discord and the like. set `SITE_URL` (or `APP_DOMAIN`) so the links are right
- `/sitemap.xml` (games, apps, public collections, the plain pages) and `/robots.txt`. profiles are left out

# levels, streaks + your week
- **XP**: a point per minute played, 50 per achievement, 10 per day played. level = floor(√(xp / 25)) + 1. the level
  shows next to names on comments, profiles, leaderboards and the chat friends list
- **streak**: days in a row with at least a minute of play. new achievements at 7 and 30 days
- **weekly recap**: `/recap`. every monday people get a notification with last week's playtime, most played games
  and highlights, and an email too if they turn it on in Settings → Privacy & saves (needs mail set up)

# reactions + editing
comments and chat messages (DMs and groups) take emoji reactions from a fixed set of 8. you can edit your comments any
time, and edit or delete your chat messages for an hour after sending. edited things say "edited", and moderators see
what a reported message said before it was changed (admin → Reports). group chats show "x is typing..." and
"Seen by ..." like DMs (typing needs the realtime server, edits from others show up live with it, or when the chat is
reopened without it).

# safety
- **rate limits** (`classes/watrlabs/watrkit/ratelimit.php`): sign in, sign up, password resets, 2FA, friend
  requests, votes, reports, uploads, AI messages, collections and more. over the limit gets a 429 with `Retry-After`.
  5 wrong passwords lock that username for 15 minutes, 10 from one address lock that address. after 3 wrong ones from an
  address, signing in asks for the captcha too (when Turnstile is set up)
- **bans and mutes**: admin → Users → someone. ban or mute for an hour, a day, 3 days, a week, 30 days or (bans only)
  forever, with a reason they get to see. muted people can still play but can't chat, comment, react, or make things
  others see. both lift themselves when the time's up. every ban, mute and lift is in their history on that page

# cron + health
`bin/cron.php` does the timed jobs: health checks with alerts, lifting bans and mutes that ran out, weekly recap
emails, cleaning up old rate limit rows. run it every 5 minutes:
```
*/5 * * * * php /www/wwwroot/games.watr.lol/bin/cron.php >> /www/wwwroot/games.watr.lol/storage/logs/cron.log 2>&1
```
`php bin/cron.php health` prints the checks. the same checks are on the admin dashboard (System). when one starts or
stops failing it posts to `ALERT_WEBHOOK` (a Discord or Slack webhook url) and/or emails `ALERT_EMAIL`. without the
cron, recaps still arrive as notifications when people open `/home`, they just don't get emailed.

`GET /health` answers 200 `{"status":"okay"}` while the database works and 503 when it doesn't, for uptime monitors
(UptimeRobot, Cloudflare health checks...).

# deploying
on the server, as root: `bin/deploy.sh` (or `DRY_RUN=1 bin/deploy.sh` to see what would change). it backs up the
database and code, clones the branch, runs new migrations before the code that needs them, copies the code over without
touching `.env`, `phinx.php`, `vendor/`, `storage/`, uploads or `public/game-files/`, runs `composer install` if the
lock file changed, clears the twig cache, and restarts `watr-realtime` if `realtime/` changed. `BRANCH=main` for
another branch. it never deletes files from the site.

# accounts
- **forgot password**: emails a link that works once within an hour. only works for accounts with an email, which
  people can add or change in settings. set `MAIL_HOST` etc in `.env` (see `.env.example`); without it and with
  `APP_DEBUG=true`, emails land in `storage/logs/mail.log`
- **two-factor sign in**: settings → Security. authenticator app codes (TOTP), plus 8 one-time recovery codes.
  the secret is stored encrypted (AES-GCM with a key derived from `encryptionKey`), and each code works once
- **sessions**: settings → Security lists every device you're signed in on, and signs out any of them
- **privacy**: "share what I'm playing" (on by default) controls "Playing X" in friends' chat trays, the friends
  activity feed on `/home`, and the playtime on your profile
- **your data**: download everything as JSON, or delete the account (password, plus a 2FA code if it's on)

# friends, groups + notifications
- the chat tray shows what friends are playing right now, and has group chats: pick two or more friends, anyone in
  the group can add their own friends or rename it, the owner can remove people. reports on group messages land in
  admin → Reports → Group chats
- the bell in the top bar: friend requests, @mentions in comments, achievements, answers to game requests, people
  adding you to groups, and anything an admin sends from admin → Site & themes → Notify everyone. pushed instantly
  with the realtime server, checked with the chat's polling without it
- achievements show on profiles (`classes/watrlabs/social/achievements.php` has the list)
- `/home` has a feed of what friends did (favorites, comments, achievements, new games they tried), plus new games

# friends + chat
signed in people get a chat tray in the bottom left. they can find people by username, send friend requests, and
message friends (only friends, like roblox). messages can have an image. it stays open on the same chat as you move
between pages.

- **reports**: hover a message from someone else and hit the flag. reports land in admin panel → Reports with the
  messages around it, where you can dismiss, remove the message, or remove it and ban the sender
- **blocking**: from the tray's ··· menu or someone's profile. blocked people can't find you, request you or message you
- **filter**: admin panel → Site & themes → Chat filter. listed words get replaced with #### when a message is sent
- **limits**: 20 messages a minute, 40 images a day, 200 friends
- chat images are stored in `storage/private/chat/` and only shown to the two people in the chat (and admins)
- with the realtime server running (below) messages, "seen", "typing..." and removals show up instantly. without it
  the tray polls instead: every 3s with a chat open, slower otherwise, 45s in a background tab

# realtime server
`realtime/` is a small node websocket server. PHP still saves everything and checks who's allowed to message who;
after saving, it tells node "send this to user 12" and node pushes it to that person's open tabs. node never touches
the database. browsers sign in to it with a token PHP signs, so pick a long random secret:

```bash
cd realtime
npm install --omit=dev
REALTIME_SECRET="same-as-.env" ALLOWED_ORIGINS="https://watr.lol" node server.js
```
then in the site's `.env`: `REALTIME_URL="wss://watr.lol/ws"`, `REALTIME_SECRET="same-as-node"`
(and `REALTIME_INTERNAL_URL` if node isn't on `http://127.0.0.1:3001`).

it listens on 127.0.0.1:3001 by default, so put it behind the web server for `wss://`. nginx:
```nginx
location /ws {
    proxy_pass http://127.0.0.1:3001;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_read_timeout 120s;
}
```
apache (mod_proxy_wstunnel): `ProxyPass /ws ws://127.0.0.1:3001/`

keep it running with systemd, pm2, or whatever you like (`pm2 start server.js --name watr-realtime`). `GET /health`
answers without the secret if you want to monitor it. if node goes down, chat quietly falls back to polling.

# proxy
`/proxy` is a web proxy: search or type an address and the site opens inside the page, with back / forward / reload,
fullscreen and "open in a new tab". `/proxy?url=wikipedia.org` opens a site straight away. only signed in people can
use it, and it only shows up in the sidebar once it's set up.

it's [Scramjet](https://github.com/MercuryWorkshop/scramjet). the rewriting and even the TLS happen in the browser
(a service worker plus a wasm transport), and `proxy/` is a small node service that serves those files and runs a
[Wisp](https://github.com/MercuryWorkshop/wisp-protocol) server: one websocket per browser, many TCP connections out.
the server never sees inside https, it only knows which sites people open. what makes it fast:
- everything starts loading when `/proxy` opens, not when you press Go, and the connection to your search engine is
  opened early. while you type an address (or hover a quick link) that site's connection is opened too
- the browser-side files are served as brotli (the wasm transports go from ~1.7MB to ~600KB) with a versioned url, so
  after the first visit they come from the browser's cache. the compressed copies are kept on disk, so restarts are
  instant
- DNS answers are cached for 10 minutes and IPv4 is tried first (a site with broken IPv6 otherwise costs a timeout)
- two fixes on top of Scramjet in `proxy/public/sw.js`: sites that set cookies on their scripts and images
  (wikipedia, most big sites) used to freeze half loaded, and the rewriter script is built once instead of per page

it can't reach the server itself or anything on its network (private and loopback addresses are refused), only ports
80, 443, 8080 and 8443 (so no sending mail through it), and DNS goes through Cloudflare's 1.1.1.3 (blocks malware and
adult sites). some sites (reddit, google sign in) block proxies, and heavy use from one server IP gets CAPTCHAs.
anything people do through it comes from the server's IP, so keep an eye on it.

setup:
```bash
cd proxy
npm ci --omit=dev
PROXY_SECRET="same-as-.env" ALLOWED_ORIGINS="https://games.watr.lol" node server.js
```
then `PROXY_SECRET="same-as-node"` in the site's `.env`. other settings (`PROXY_DNS`, `PROXY_PORTS`,
`PROXY_BLOCKED_HOSTS`, `PROXY_MAX_PER_USER`, `PORT`) are explained at the top of `proxy/server.js`.

it listens on 127.0.0.1:3002, so send `/proxy/` to it (with the slash: `/proxy` itself is the PHP page and stays with PHP). nginx:
```nginx
location ^~ /proxy/wisp/ {
    proxy_pass http://127.0.0.1:3002;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_read_timeout 1h;
    proxy_send_timeout 1h;
    proxy_buffering off;
}
location ^~ /proxy/ {
    proxy_pass http://127.0.0.1:3002;
    proxy_http_version 1.1;
    proxy_set_header Connection "";
}
```
keep it running with systemd (`/etc/systemd/system/watr-proxy.service`), `bin/deploy.sh` restarts it when `proxy/`
changes:
```ini
[Unit]
Description=Water Games proxy
After=network.target

[Service]
WorkingDirectory=/www/wwwroot/games.watr.lol/proxy
Environment=PROXY_SECRET=same-as-.env
Environment=ALLOWED_ORIGINS=https://games.watr.lol
ExecStart=/usr/bin/node server.js
Restart=always
User=www

[Install]
WantedBy=multi-user.target
```
`GET /proxy/health` answers without the secret, and the admin health checks (and `bin/cron.php` alerts) include it.
to try it locally without nginx: run `php -S 127.0.0.1:8000 -t public dev-router.php`, then
`DEV_UPSTREAM=http://127.0.0.1:8000 PORT=3002 PROXY_SECRET=... node server.js` in `proxy/` and open
`http://localhost:3002/proxy` (node passes everything else on to PHP).

# page loading
links and search forms load the next page in place (fetch + swap the main area), so the music player and chat
tray never reload. anything unusual (a file link, a different site build after a deploy, an error) falls back to a
normal page load. put `data-reload` on a link or form to always do a full load. page scripts run again each time
their page is shown, so new ones should keep their variables inside a function like the existing ones do.

# installing it as an app
`public/manifest.webmanifest` and `public/sw.js` make the site installable (Chrome's install button, "add to home
screen" on phones, Chromebook shelf). the service worker only caches files under `/assets/` and an offline page.
pages and the api always come from the network.

# keyboard shortcuts
`?` shows them all. `/` search, `g` then `h`/`g`/`m`/`n`/`c` for home/games/music/notifications/collections, `g` then
`r` for a random game, `c` chat, and on a game
`f` fullscreen and `t` theater mode. they don't fire while you're typing.

# backups
`bin/backup.sh` dumps the database named in `.env` into `storage/backups` (gzipped, newest 14 kept, `KEEP=30` for
more). to run it every night, add a cron job (aaPanel → Cron → Shell script, or `crontab -e`):
```
0 4 * * * /www/wwwroot/games.watr.lol/bin/backup.sh >> /www/wwwroot/games.watr.lol/storage/logs/backup.log 2>&1
```
copy the backups somewhere off the server too, a backup on the same disk doesn't help if the disk dies.
uploads (`public/uploads`, `storage/private`) are plain files, back them up with the rest of the server.

# tests
```bash
vendor/bin/phpunit                    # everything
vendor/bin/phpunit --testsuite unit   # no database needed
```
the `database` suite uses the database in `.env`, inside a transaction that's always rolled back, and skips itself if
it can't connect.

# themes
four themes people can pick (Deep, Abyss, Reef, Foam) plus seasonal ones that switch on by date:
New Year, Valentine's, St. Patrick's, Easter, Halloween and Christmas (with snow).
people pick from the footer or their settings page; guests get a cookie, accounts get it saved.
add `?theme=halloween` (or any theme id) to a url to preview one without changing anything.

# adding games
the admin panel is easiest. you can still insert rows directly:
```sql
INSERT INTO games (name, description, gamePath, gameIcon, plays)
VALUES ('Slope', 'Roll down the slope.', '/game-files/slope/index.html', '/game-files/icons/Slope.png', 0);
```

# adding apps
same table as games, just set `type` to `app`:
```sql
INSERT INTO games (name, description, gamePath, gameIcon, plays, type)
VALUES ('Calculator', 'A plain calculator.', '/game-files/calculator/index.html', '/game-files/icons/Calculator.png', 0, 'app');
```
some sites refuse to load inside an iframe, the "open in new tab" button on the app page covers that

# adding music
```sql
INSERT INTO tracks (title, artist, filePath, coverPath, duration)
VALUES ('Low Tide', 'watrlabs', '/music-files/low-tide.mp3', '/music-files/covers/low-tide.jpg', 214);
```
`artist`, `coverPath` and `duration` (seconds) are optional. the web server needs to support range requests
for seeking to work (apache and nginx do by default, `php -S` doesn't)

# lyrics
the player looks lyrics up the first time a song plays and saves them (songs with nothing get asked again after a week).
**[lrc.red](https://lrc.red)** goes first: it has word by word timing (in Apple Music's TTML format) for most songs.
if it has nothing, **[lrclib.net](https://lrclib.net)** is next, which has time synced lyrics and sometimes a
[Lyricsfile](https://github.com/tranxuanthang/lyricsfile/blob/main/SPECIFICATION.md). either way the lyrics are saved
as a Lyricsfile (`tracks.lyricsFile`) plus plain LRC as a fallback, and `tracks.lyricsSource` says which site they came
from (the player credits it). lines go quiet when they end, words light up as they're sung, and background vocals are
part of the line in brackets. a match needs the same title plus the artist or a close enough length, so a live cut
doesn't get studio timing. changing a track's title, artist or length in the admin panel looks its lyrics up again.

lrc.red doesn't say where its lyrics come from or that it has a licence for them. they look copied from Apple Music,
so it's a takedown risk: if it goes away, lookups fall through to lrclib on their own (`classes/watrlabs/music/lrcred.php`).

# AI chat
the easy way is **admin panel → AI**: add a provider (pick Ollama, Anthropic compatible or OpenAI compatible, give it a
base url and key), hit "fetch available models" to add the ones you want, then switch the AI on. you can have as many
providers as you like, e.g. a local ollama, openrouter and anthropic side by side. keys are stored encrypted with
`encryptionKey` / `encryptionIv`, so don't change those once you've saved keys.

you can also set providers in `.env` (`OLLAMA_MODELS`, `ANTHROPIC_MODELS`, `OPENAI_MODELS`, see `.env.example`); those
show up in the panel as read-only. settings saved in the panel (on/off, limits, tools, extra instructions, default
model) win over the matching `AI_*` values in `.env`. people need an account to use it, chats are saved per user.

- **ollama** uses ollama's own api. the site asks ollama what each model supports, so text-only models
  don't get sent images or tools
- **anthropic** works with api.anthropic.com or anything compatible. on api.anthropic.com it also turns on prompt
  caching and server-side fallbacks (a declined request gets retried on anthropic's recommended fallback model)
- **openai** works with anything that speaks `/chat/completions`: openai, openrouter, lm studio, vllm, groq...

tools the model can use: current time, a calculator, searching the site's games/apps/music, weather (open-meteo,
no key needed) and, if you add `fetch` to `AI_TOOLS`, reading web pages. the page fetcher only talks to public
addresses so it can't be pointed at your own network.

images people attach are stored in `storage/private/ai/` (outside the web root) and only shown to whoever uploaded them.

answers stream over server-sent events. if you're behind nginx that's handled with `X-Accel-Buffering: no`;
if something else buffers responses (gzip on `text/event-stream`, some proxies) answers will show up all at once
at the end instead of streaming.

# notes
- run migrations after pulling (`vendor/bin/phinx migrate`). the latest one adds playtime, cloud saves, categories,
  votes, requests, notifications, achievements, groups, playlists, 2FA and the admin log
- the admin panel also has: charts on the dashboard, Requests (mark added/declined, or "add it now"), broken game
  reports under Reports, Categories (from the Games page), a Log of every admin action, and Notify everyone
- uploads check the file type with php's `fileinfo` extension when it's there, and by reading the file's first bytes
  when it isn't (aaPanel's php builds leave fileinfo out by default)
- the turnstile captcha on sign up only turns on when `CONFIG_CaptchaEnabled=true` AND both turnstile keys are set
- signup/login IPs are stored encrypted with `encryptionKey`/`encryptionIv` so the alt limit can match them
- `/randTest` and `/auth/isAuthed` only exist when `APP_DEBUG=true`
- design notes (colors, fonts, how pages are laid out) are in `DESIGN.md`
