# Water Games design notes

Short reference so new pages match the old ones. Tokens live at the top of
`public/assets/css/base.css`; use those instead of hardcoding colors.

## Color

Colors are tokens, never hardcoded hex. `base.css` `:root` holds the default
theme (Deep); every other theme in `themes.css` redefines the same tokens.

| Token            | Deep      | Used for                                   |
|------------------|-----------|--------------------------------------------|
| `--bg`           | `#002131` | page background                            |
| `--surface`      | `#032b40` | navbar, sidebar, panels                    |
| `--raised`       | `#08374f` | cards, inputs, game tile backgrounds       |
| `--accent`       | `#01537a` | primary buttons, banner strip              |
| `--on-accent`    | `#F5F1ED` | text on `--accent`                         |
| `--link`         | `#7cc4e4` | links, focus rings, active nav item        |
| `--text`         | `#F5F1ED` | body text                                  |
| `--muted`        | `#A99985` | secondary text, metadata (plays, dates)    |
| `--danger`       | `#f08a7a` | errors, destructive buttons                |
| `--line`         | text 10%  | borders (`--line-strong` is 20%)           |

Tints are `color-mix(in srgb, var(--token) N%, transparent)` so they follow the
theme. No gradients, no blur, no glow (the only gradient is the hard-stop fill
on the music seek bar). Code blocks stay dark (`--code-bg`) in every theme.

## Themes

- Base themes people can pick: Deep (default), Abyss, Reef, Foam (light).
- Seasonal themes switch on by date for anyone on "Site default": New Year,
  Valentine's Day, St. Patrick's Day, Easter, Halloween, Christmas. Each one is
  a palette, an emoji after the wordmark, and an optional greeting strip.
  Christmas also gets snow (respects reduced motion, can be turned off).
- Every palette is checked at 4.5:1 for text, muted text, links, button text
  (normal, hover and pressed) and errors. Pressed buttons go darker, not
  lighter, so white text keeps its contrast.
- Dates and names live in `classes/watrlabs/watrkit/themes.php`; adding a theme
  means a block in `themes.css` plus an entry there.

## Type

- **Archivo** at its widest (`font-stretch: 125%`, weight 900) for page
  titles, the wordmark and tile letters. It's square and heavy to match the
  blocky layout. It has a real italic, and italic is the emphasis move
  ("*meant* to be played"); use it sparingly.
- **Pliant** for everything else.
- Scale: 13 / 15 / 18 / 26 / 32 / 46px. Headings sit tight (line-height 1.08).
  The wide cut runs big, so phone sizes drop a step (25px page titles).

## Layout

- App shell: top bar, collapsible left rail, scrolling main column with the
  footer at the bottom of the scroll.
- Content max width 1180px, 32px side padding (16px on phones).
- Square corners everywhere, avatars and player buttons included (`--radius`
  is 0). Depth is flat panels and 1px borders; the one exception is the hard
  3px offset block a game tile gets on hover.
- Game tiles: square icon, name, then plays in sand. That's it. Apps use the
  same tile.
- Music is a numbered list, not tiles. The player bar sits under everything
  and only shows up once something has been played.

## Personality

The blob emoji in `assets/images/BlobEmoji` are the illustrations. Use one in
empty states and error pages instead of icons or stock art. Keep the jokes in
the copy; keep the chrome plain.
