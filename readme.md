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
- `/proxy`, `/apps`, `/music` are "coming later" placeholders

# adding games
there's no admin panel yet, insert rows into `games` directly:
```sql
INSERT INTO games (name, description, gamePath, gameIcon, plays)
VALUES ('Slope', 'Roll down the slope.', '/game-files/slope/index.html', '/game-files/icons/Slope.png', 0);
```

# notes
- run migrations after pulling, there's a new `favorites` table
- the turnstile captcha on sign up only turns on when `CONFIG_CaptchaEnabled=true` AND both turnstile keys are set
- signup/login IPs are stored encrypted with `encryptionKey`/`encryptionIv` so the alt limit can match them
- `/randTest` and `/auth/isAuthed` only exist when `APP_DEBUG=true`
- design notes (colors, fonts, how pages are laid out) are in `DESIGN.md`
