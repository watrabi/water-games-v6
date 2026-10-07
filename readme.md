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

# adding games
there's no admin panel yet, insert rows into `games` directly:
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
set `AI_ENABLED=true` in `.env` and list models for at least one provider (`OLLAMA_MODELS`, `ANTHROPIC_MODELS`,
`OPENAI_MODELS`). `.env.example` has every setting. people need an account to use it, chats are saved per user.

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
- run migrations after pulling, there are new `favorites`, `tracks` and `ai_*` tables and a `type` column on `games`
- the turnstile captcha on sign up only turns on when `CONFIG_CaptchaEnabled=true` AND both turnstile keys are set
- signup/login IPs are stored encrypted with `encryptionKey`/`encryptionIv` so the alt limit can match them
- `/randTest` and `/auth/isAuthed` only exist when `APP_DEBUG=true`
- design notes (colors, fonts, how pages are laid out) are in `DESIGN.md`
