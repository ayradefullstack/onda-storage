# ONDA brand colour system

The source of truth is the official logo. Pixel sampling confirms **Blue `#1B669D`** and **Green `#1C9976`**.

| File | Purpose |
|---|---|
| `onda-tokens.css` | Semantic tokens for Light, Dark and System, plus the Tailwind v4 `@theme inline` mapping and the `dark:` variant |
| `useAppearance.ts` | Proposed Vue composable: `'light' \| 'dark' \| 'system'`, persisted, follows OS changes |
| `brand-preview.html` | Self-contained Light/Dark preview with live WCAG contrast checks |
| `brand-preview.template.html` + `build-preview.py` | Rebuild the preview after editing tokens (`python3 design-system/build-preview.py`) |
| `onda-logo.png`, `onda-mark.png` | The official logo, and the mark cropped from it (not redrawn) |

## Roles
- **Blue = interactive:** buttons (`primary-solid`), links and blue text (`primary-text`), focus (`ring`), active and selected states (`primary`, `primary-soft`).
- **Green = restrained accent:** success and "protected" states (`accent-text`, `accent-soft`), icons, the second chart series (`accent`), and a rare secondary action (`accent-strong`). Never the main call-to-action.
- **Logo:** never recoloured. On dark or brand surfaces it sits on a white plate.
- **Borders:** `border` is for decorative dividers only. Input and checkbox edges use `input-border`, which meets 3:1 (WCAG 1.4.11).

## Where the spec was extended (derived tokens) and why

| Role | Spec value | Problem | Derived token |
|---|---|---|---|
| Light: green text | `#1C9976` | 3.58:1 on white | `accent-text` `#147A5E`, 5.28:1 |
| Light: field borders | `#E1EAF1` | 1.22:1 | `input-border` `#788A9D`, 3.55:1 |
| Dark: button with white label | `#347FB8` | 4.31:1 | `primary-solid` `#2C6EA2`, 5.44:1 |
| Dark: blue links/text | `#347FB8` | 3.86:1 on surface | `primary-text` `#7EB4E2`, 7.54:1 |
| Dark: field borders | `#30465B` | 1.50:1 on input | `input-border` `#61798F`, 3.23:1 |

Every spec value is still used, in the roles where it passes. For example, dark `#347FB8` serves as the focus ring and active indicator at 3.86:1 against the 3:1 UI threshold.
