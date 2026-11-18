# ONDA: authentication redesign (Phases 1–4)

> Status: **the HTML preview is ready for review. The Laravel/Vue app has not been changed** (approval gate, Phase 4).
> Preview: `auth-preview.html` (a single self-contained file. Authentication is simulated and nothing is sent to a server).

## 0. Scope note: what this is based on

The ONDA Storage repository was **not available in this session**: the working directory was empty and no matching repo was reachable. This audit and design are therefore based on the **four screenshots provided**: login AR/light, login EN/dark, register EN/dark and register AR/light. Everything about routes, controllers, Inertia props and component names below is an **assumption to verify in Phase 5**. The repo itself was not inspected.

---

## 1. Current authentication UX audit (from screenshots)

| # | Finding | Severity |
|---|---------|----------|
| A1 | **Arabic password field: the eye icon collides with the masked dots** (`👁•••••••`). The icon sits on the left, which is where the LTR value starts, and there is no matching padding. The confirm-password field in the register form has the same problem. | Critical (RTL) |
| A2 | **Letter-spacing applied to Arabic** in the brand-panel footer ("الـجـمـهـوريـة…"). Tracking breaks Arabic letter joining and gives a monospace look. | Major |
| A3 | **Browser autofill paints inputs light-blue** in both themes, which breaks the dark theme and the visual system. | Major |
| A4 | **The primary button changes identity between themes**: navy in light mode, sky-blue in dark mode. The brand reads as two different products. | Major |
| A5 | **Brand panel position is inconsistent**: top-aligned on login, vertically centred on register, where it floats in empty space next to the long form. | Minor |
| A6 | **Register form is a flat wall of 10 fields** with no grouping, no progress feedback and no field-level success state. | Major (UX) |
| A7 | **The country combobox shows a chip, a chevron and a clear "×" together**, which is visual noise. The flag emoji also won't render on Windows. | Minor |
| A8 | **The divider label is uppercase in English** ("OR CONTINUE WITH EMAIL IDENTIFIER"). It is long and shouty, and "identifier" is jargon. | Minor (copy) |
| A9 | **No visible handling for typing errors that come from the keyboard language.** For example, an Arabic keyboard layout in the email or password field produces invisible failures in a masked field. | Major (RTL ergonomics) |
| A10 | **The brand panel is decorative but static**: it doesn't express the product (secure deposit of works). The feature cards repeat the same visual weight three times. | Minor (visual) |
| A11 | **Mobile behaviour is unknown** because no screenshots were provided. The 2-column layout would need an explicit mobile design. | Unknown |

What already works and should be kept: the bilingual identity (AR/FR), the theme and language toggles, passkey support, the "Arabic only" hints on the Arabic name fields, the +213 phone prefix, the wilaya→commune dependency and the sovereignty/trust messaging.

## 2. Existing architecture summary (inferred, verify in Phase 5)

- Laravel 11 + Inertia + Vue 3 + TS + Tailwind v4. The pages are probably `resources/js/Pages/Auth/Login.vue` and `Register.vue`, in the Breeze-style pattern with `useForm()`, `form.post(route('login'))`, `form.errors.*` and `form.processing`.
- The login fields appear to be `email`, `password` and `remember`. Breeze conventions: `auth.failed` attaches to `email`, and `password` is reset on failure.
- The register fields come from the screenshots: first name (Latin), last name (Latin), first name (Arabic), last name (Arabic), country, wilaya, commune, mobile phone, email, password and password confirmation. **The real field names must be read from the controller/FormRequest.** The preview uses placeholder names such as `first_name_latin`.
- Passkey login exists (WebAuthn). Its flow must stay untouched.
- i18n: an AR/EN toggle exists. The mechanism (vue-i18n or Laravel lang files shared through Inertia) is still to be confirmed.

## 3. Design direction: "The vault of protected works"

- **Concept:** every deposit with ONDA is a work that is certified, stacked and sealed. The brand stage shows a CSS-3D stack of documents (film, book and music works) under a hovering shield seal, with an orbiting ring of light. When sign-in succeeds, the **seal stamps the top certificate**. The motion carries the product's meaning and isn't just decoration.
- **Identity:** deep petrol-navy to teal to emerald, which builds on the existing ONDA palette. **One primary hue family in both themes** (fixes A4). The stage stays dark in both themes and acts as the brand anchor.
- **Type:** a single family, IBM Plex Sans Arabic, covering both Arabic and Latin. Arabic text gets **no letter-spacing** (fixes A2). Latin headings get light negative tracking.
- **Hierarchy:** the form comes first in DOM and reading order, on the start side. The stage sits on the end side and is sticky on desktop. On mobile the stage becomes a compact hero band.
- **Register:** the same fields, unchanged, grouped into three numbered sections: Identity, Residence & contact, Security. Each section turns green with a check when complete (fixes A6).

## 4. Animation & 3D strategy

- **Pure CSS 3D** (`perspective` + `preserve-3d`) plus about 25 lines of JS for pointer parallax. **No Three.js**: the benefit didn't justify roughly 150 KB of library code.
- Only `transform` and `opacity` are animated. The one exception is the card height morph during view switches, which is a single-element height transition that is cleared afterwards.
- Parallax runs on fine pointers only, uses an rAF lerp, and stops when it settles.
- The scene **pauses when offscreen or when the tab is hidden**.
- **Reduced motion:** the OS setting or the in-preview toggle sets `html[data-motion=reduce]`. Every animation then resolves instantly to its final, visible state, particles are removed and parallax is disabled.
- Micro-interactions:
  - Focus: the border tints, a 4px ring appears, an underline grows from the start edge of the value, and the leading icon scales.
  - Errors: a single 420 ms shake.
  - Messages expand with a `grid-template-rows` transition, so the layout grows smoothly instead of jumping.
  - Submit button: three labels (idle, busy, done) stacked in the same grid cell, so the **width never changes**. It shows a spinner and an indeterminate bar, then a drawn check on success.
  - View switches: the outgoing view slides out in the reading direction, the incoming view's elements enter with a 45 ms stagger, and the card height morphs between the two.

## 5. RTL/LTR implementation plan

| Element | Direction | Rule |
|---|---|---|
| Page | `dir=rtl` (AR) / `ltr` (EN) | `<html dir>` switches with the language. Use logical CSS properties only (`inset-inline`, `padding-inline-start`…). |
| Email, password, Latin names, phone | **`dir=ltr` on both the input and its `.control` wrapper** | The whole control is LTR. The leading icon sits next to where the value starts (left), and the reveal/state icons sit at the right. The caret, placeholder and value all start on the left, with no gap between icon and text and no collision (fixes A1). |
| Arabic names | `dir=rtl` explicitly | Stays RTL even when the UI is in English. |
| Labels, hints, errors | Follow the page | Latin fragments are wrapped in `<bdi>` (e.g. `Caps Lock`, `nom@domaine.dz`, `05`). |
| Arrows | Direction-aware | "Back" points toward the start and "Submit" toward the end. Mirrored using `[dir=rtl] .flip-rtl`. |
| Motion | Direction-aware | `--dir` (−1/1) drives slide directions. The focus underline grows from the edge where the value starts. |

RTL-specific ergonomics added:
- An **Arabic-characters-in-email/password warning** (keyboard layout).
- A **Caps Lock warning**.
- **Arabic-Indic digits (٠-٩, ۰-۹) normalised** to ASCII as the user types in the phone field, with the caret preserved.
- Phone digits grouped on blur.
- Selection and caret preserved when the password is revealed.

## 6. Risks for implementation

1. **Field names and validation rules** must be taken from the real FormRequest. The preview's rules (minimum 8 characters, Arabic-only and Latin-only regexes, DZ mobile pattern) are illustrative only.
2. **Country, wilaya and commune data**: the preview uses sample communes. Keep the existing data source and combobox behaviour, including whether wilaya/commune apply outside Algeria.
3. **The passkey flow** (WebAuthn JS) must be wired to the new button unchanged.
4. **Autofill styling** differs in Firefox. Test real password managers (1Password, Bitwarden, Chrome) with LTR inputs inside an RTL page.
5. **Tailwind v4:** the tokens map to `@theme` CSS variables. The 3D scene stays as a scoped `<style>` block in its component, which is clearer than utility classes for 3D transforms.
6. **Server errors arrive after `form.post`**, so error display has to be bridged from `form.errors` to the field component (prop `error`), and the alert from `auth.failed`.
7. **Fonts:** self-host IBM Plex Sans Arabic (or keep the app's current font) instead of loading it from Google Fonts in production.

## 7. Proposed Vue components (Phase 5, after approval)

```
resources/js/Layouts/AuthLayout.vue            // shell: top bar, stage slot, card, trust line
resources/js/Components/Auth/AuthStage.vue     // brand + features + footer (i18n)
resources/js/Components/Auth/VaultScene.vue    // CSS-3D scene; props: state 'idle'|'busy'|'stamped'
resources/js/Components/Auth/AuthField.vue     // label/hint/error/state icon; props: id, label, error, dir, required
resources/js/Components/Auth/PasswordInput.vue // reveal toggle (aria-pressed), caps-lock + Arabic-char warnings
resources/js/Components/Auth/PhoneInput.vue    // prefix, digit normalisation, grouping
resources/js/Components/Auth/SubmitButton.vue  // idle/busy/done labels; bound to form.processing
resources/js/Components/Auth/FormSection.vue   // numbered fieldset with "complete" state
resources/js/Composables/useKeyboardHints.ts   // Arabic-char / CapsLock detection
resources/js/Composables/useReducedMotion.ts
resources/css/auth.css (or @theme tokens)      // design tokens, light/dark
```
`Login.vue` and `Register.vue` keep their existing `useForm` and `route()` calls. Only the template and markup change.

---

## 8. Phase 3: Team-lead review (what was found and fixed)

Two independent read-only reviewer agents tested the prototype with Playwright: one for RTL/LTR and accessibility, one for UX QA, responsive layout and motion. The fixes were then applied and re-verified with Playwright.

**Fixed after review**
- Input and checkbox borders were about 1.3:1. A new `--field-line` token brings them to **3.30:1** against the card (WCAG 1.4.11).
- The placeholder opacity was removed, which fixes light-mode placeholder contrast.
- The strength-meter text colours failed contrast. The text now uses neutral `--text-2`, and only the bars carry colour.
- The section "مكتمل" (complete) label was exposed to screen readers while the section was still invalid. It now uses `visibility: hidden` until the section is done.
- An error-summary link pointed at the disabled commune select. Disabled controls are now excluded, so the wilaya error covers it.
- The country option rendered as "(213+)" in RTL. It now uses LRM marks and renders "(+213)".
- The error summary didn't re-translate on a language switch. It is now stored as state and re-rendered.
- The summary was a wall of 10 links. It is now capped at 4 links plus "and N more".
- On rejected credentials, focus went to the password field while the error was on the email field. Focus now goes to the email field, which matches the Laravel `auth.failed` convention.
- Stale error text stayed in the `aria-describedby` target after an error was fixed. The message is now emptied once it collapses.
- Browser Back left the page. View switches now use `pushState` and listen for `popstate`.
- Changing the country left the Algerian wilaya and commune selected. They are now reset.
- The seal glow was clipped during the stamp animation. The animation now uses only translate and `will-change`, and the stamp mark reads as contact.
- Small targets were enlarged: 44px top-bar controls on mobile and padded text links. Text selection on double-click of buttons is now prevented.
- The skip-link text follows the current view. The duplicated "Preview tools" accessible name is fixed.
- The phone placeholder was clipped at 320px. The ISO chip is hidden below 600px.

**Kept deliberately**
- `exemple@domaine.com` and `Mot de passe` placeholders in the Arabic UI: you asked for these specifically.
- Two layout animations are kept: the card height morph during view switches and the message expand (`grid-template-rows`). Both are single-element, short, and disabled under reduced motion.

**Open questions for the real app**
- Do wilaya and commune apply when the country isn't Algeria?
- What are the real password rules?
- What happens after registration (email verification?)
