# Water Games design notes

Short reference so new pages match the old ones. Tokens live at the top of
`public/assets/css/base.css`; use those instead of hardcoding colors.

## Color

| Token          | Value     | Used for                                   |
|----------------|-----------|--------------------------------------------|
| `--deep`       | `#002131` | page background                            |
| `--shelf`      | `#032b40` | navbar, sidebar, footer                    |
| `--raised`     | `#08374f` | cards, inputs, game tile backgrounds       |
| `--current`    | `#01537a` | primary buttons (hover `#026a9a`)          |
| `--shallows`   | `#7cc4e4` | links, focus rings, active nav item        |
| `--foam`       | `#F5F1ED` | body text                                  |
| `--sand`       | `#A99985` | secondary text, metadata (plays, dates)    |
| `--coral`      | `#f08a7a` | errors                                     |

Borders are `--line` (foam at 10%). No gradients, no blur, no glow.

## Type

- **Playfair Display** for page titles and the wordmark. Italic is the
  emphasis move ("meant to be played"); use it sparingly.
- **Pliant** for everything else.
- Scale: 13 / 15 / 18 / 24 / 34 / 52px. Headings sit tight (line-height 1.1).

## Layout

- App shell: top bar, collapsible left rail, scrolling main column with the
  footer at the bottom of the scroll.
- Content max width 1180px, 32px side padding (16px on phones).
- Radius is 6px on everything except avatars.
- Game tiles: square icon, name, then plays in sand. That's it.

## Personality

The blob emoji in `assets/images/BlobEmoji` are the illustrations. Use one in
empty states and error pages instead of icons or stock art. Keep the jokes in
the copy; keep the chrome plain.
