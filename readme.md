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
- `/` landing, `/home` (signed in), `/games` (search with `?q=`, sort with `?sort=popular|newest|name`)
- `/games/{id}` plays a game: the iframe loads `gamePath`, the tile uses `gameIcon` (falls back to the first letter if it's empty)
- `/favorites`, `/users/{username}`, `/settings`, `/auth/logout`
- `/terms`, `/privacy`, `/credits`
- `/apps`, `/apps/{id}` same as games but for non-game stuff (rows in `games` with `type = 'app'`)
- `/music` track list, with a player bar that follows you between pages
- `/ai` chat with saved conversations, tools and image input (see below)
- `/proxy` is a "coming later" placeholder

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
- there's no websocket server, the tray polls: every 3s with a chat open, slower otherwise, 45s in a background tab

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
- run migrations after pulling, there are new `favorites`, `tracks`, `settings`, `ai_*`, `friendships`, `blocks` and
  `chat_*` tables, a `type` column on `games` and `admin` / `banned` / `theme` / `last_seen` columns on `users`
- the turnstile captcha on sign up only turns on when `CONFIG_CaptchaEnabled=true` AND both turnstile keys are set
- signup/login IPs are stored encrypted with `encryptionKey`/`encryptionIv` so the alt limit can match them
- `/randTest` and `/auth/isAuthed` only exist when `APP_DEBUG=true`
- design notes (colors, fonts, how pages are laid out) are in `DESIGN.md`
