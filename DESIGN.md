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

Borders are `--line` (foam at 10%). No gradients, no blur, no glow (the only
gradient is the hard-stop fill on the music seek bar).

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
