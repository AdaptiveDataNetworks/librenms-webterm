# Design


The visual world is **LibreNMS's**, not ours. This file does not invent one; it
records the host's conventions we are bound to, and the handful of decisions
that are genuinely ours to make inside them. Read [PRODUCT.md](PRODUCT.md) first
— it records who this is for and why.

The governing rule, from PRODUCT.md's fourth principle: **a guest in LibreNMS's
house.** Looking better than the host is a failure, not a win. Brand lives in
precise details and in the copy, never in chrome of our own.

## Where the world comes from

Every surface renders inside `layouts.librenmsv1`. That layout supplies
Bootstrap 3, jQuery, select2 and Font Awesome, plus `styles.css` (light),
`tw_dark.css` (dark, switched by a `dark` class on `<html>`), and whatever
alternative site stylesheet the operator has chosen — `mono.css` restyles
`.panel-default > .panel-heading` to a dark bar, so even panel chrome is not
ours to assume.

**Bootstrap 3 is the whole vocabulary.** Core still imports
`html/css/bootstrap.min.css`, its own `<x-panel>` emits
`panel-heading` / `panel-title` / `panel-body` / `panel-footer`, and
`panel panel-default` appears in 135 files under `includes/html`.

**Tailwind is loaded and unusable by us.** Core's `app.css` does
`@import "tailwindcss" prefix(tw)` and its newer components use `tw:` classes,
but that CSS is built by core's Vite against core's own source paths. An
installed plugin under `vendor/` is never scanned and cannot trigger a rebuild,
so a `tw:` class in our views produces no CSS at all. Never reach for one.

**We add no stylesheet.** There is no build step and no asset pipeline, and
`tools/composer-guard.php` fails CI if the dependency list changes. Layout that
Bootstrap 3 has no class for is done with an inline `style` attribute carrying
geometry only — never colour.

## Tables

Every table on every surface:

```html
<div class="table-responsive">
    <table class="table table-hover table-condensed table-striped">
        <thead>
            <tr><th scope="col">Device</th>…<th scope="col"><span class="sr-only">Action</span></th></tr>
        </thead>
        <tbody>…</tbody>
    </table>
</div>
```

Each part is load-bearing and was measured, not assumed:

- **`<thead>` is not optional.** Every Bootstrap 3 and `tw_dark.css` rule that
  styles a table header is `thead`-scoped. Without one, the header row lands in
  the implicit `<tbody>`, so `.table-striped > tbody > tr:nth-of-type(odd)`
  paints *the header* as a stripe (measured `rgb(249,249,249)` on the header,
  transparent on the first data row), the 2px `border-bottom` separator never
  applies (computed `0px none` in both themes), and `white-space: nowrap` on
  header cells stops matching so labels wrap.
- **`.table-responsive`** contains horizontal overflow inside the table. Without
  it the whole page scrolls sideways at 375px — measured 329px of overflow on
  Targets and 379px on Host keys, where a SHA256 fingerprint offers no break
  opportunity at any viewport.
- **`table-hover`** is part of core's own four-class idiom and is themed in
  both modes. Rows carrying a per-row action need the row-tracking.
- **Never an empty `<th>`.** An action column gets
  `<span class="sr-only">` naming it; `.sr-only` ships in the host's Bootstrap.

## Type and headings

The host sets the scale: body 14px, `h2` 30px, `h3` 24px, `h4` 18px, in Verdana.
Never set a font-family, a font-size or a font-weight.

Run a real ladder — `h2` page title, `h3` section, `h4` sub-section. Do not skip
a level to reach for a smaller size.

## Colour

**No literal colour, ever.** Not a hex, not an `rgb()`, not a border colour. The
host has two themes and at least two alternative stylesheets; a literal follows
none of them. Every colour comes from a Bootstrap 3 class the host restyles.

### `.text-muted` is for genuinely secondary text

Measured against the host's own values it is 4.48:1 in light (AA wants 4.5) and
3.26:1 on a striped row in dark. That is the host's colour and we do not fight
it — but load-bearing content does not go in it. An instruction the operator
must follow, the answer to the question the table exists to answer, or the whole
body of a refusal panel is not secondary.

### Never `<p class="text-muted"><small>`

`tw_dark.css` sets `.dark small { color: #FFF }`, and a direct element match
beats an inherited class. So that pattern is the *dimmest* text on the page in
light and the *brightest* in dark — it silently inverts. Put the class on the
element that renders the text: `<small class="text-muted">`.

### `<code>` is for what you type

Core deliberately leaves `code` red-on-pink in both themes
(`.dark code { color:#c7254e; background:#f9f2f4 }`), so matching that is
correct and "fixing" it would be the deviation. The consequence is ours to
manage: reserve `<code>` for commands, config keys in prose and paths — things
the reader will type or search for. A machine identifier nobody types (a
fingerprint, a ULID) does not earn it; a whole column of `<code>` reads as a
column of errors. Use `<span class="text-muted">` at normal size, or an inline
`font-family: monospace` where the character grid genuinely helps.

### Status is a label with the stored word inside it

```html
<span class="label label-danger">rejected</span>
```

Bootstrap's contextual labels are themed in both modes, the **stored value stays
the visible text** so it still matches CLI output and database rows character
for character, and meaning is never carried by colour alone. Use `label-danger`
for a security event, `label-warning` for something needing attention,
`label-success` for live, `label-default` for closed or ordinary. Leave the
common, uninteresting value unlabelled so the exceptions are what the eye finds.

## Forms

- **Every control has a real `<label for>`**, or an `aria-label` where the host's
  own idiom is an unlabelled inline control. A placeholder is an example, never a
  name: it disappears at the first keystroke.
- **Explain the vocabulary where it is used.** `flow`, `principal`,
  `host_key_policy`, `algorithm_profile` and the three abilities get a
  `<span class="help-block">` in the operator's language. PRODUCT.md's audience
  arrives without this vocabulary and does not come back for months.
- **Repeated buttons name their row.** Seven buttons reading "Disable" are one
  button to a screen reader. Append `<span class="sr-only">` with the subject.
- **`.form-inline` stops applying below 768px**, where every control becomes a
  full-width block. A row of five unlabelled controls becomes five anonymous
  boxes. Use the grid (`row` / `col-sm-*` / `form-group`) for anything with more
  than two controls.

## Feedback

- The flash and error containers carry `role="status"` / `role="alert"` and
  `aria-live`, because a redirect-and-flash surface tells a screen-reader user
  nothing otherwise.
- A refusal is never painted blue. Success and failure must not share one
  channel and one colour.
- **The active tab carries `aria-current="page"`.** These are seven real page
  loads, not a JS tab widget, so `aria-current` is the right attribute — not
  `role="tab"`.

## Destructive actions confirm

Terminating a live shell, removing a grant, deleting a credential and switching
off the plugin are one-click and unrecoverable. Each gets an `onsubmit` confirm
naming what it will affect and what the consequence is — core does the same for
this class of action. Pass an attacker-influenced name through a `data-`
attribute rather than interpolating it into a JS string literal.

## Copy

The voice is already written down, in `docs/contributing/style-guide.md`, and
the existing UI copy is the best thing in the product. Preserve it; extend it in
the same register.

- **Address the reader as you. Imperative for instructions.**
- **Name the limitation and say why.** A control that is absent on purpose is
  shown with its reason, not hidden.
- **Every command block states which host and which user it runs as**, e.g.
  `# LibreNMS server, as the librenms user`. Never a `$` or `#` prompt prefix —
  it breaks copy-paste.
- **Stored values render verbatim.** `database`, `ssh_signer`, `kv2`,
  `private_key`, `pin`, `tofu_first_connect`, `allow`, `deny`, `use`, `admin`,
  `audit.view` are what an operator matches against CLI output. Explain them
  alongside; never prettify the value itself.
- **An empty state says what would put a row there.** "No sessions recorded."
  is a dead end; naming the event that creates one is not.
- **Do not claim more closure than exists.** A security claim that overstates the
  restriction is the one direction a default-deny product must never be wrong in.

## Translation keys

`__('device')` resolves to LibreNMS's `lang/en/device.php` and returns the whole
file as an array, which is fatal inside `{{ }}`. Never a lowercase single-word
key — use a phrase or a capitalised label.

`tests/Feature/ViewTranslationKeyTest.php` enforces the shape, but it matches
literal `__('...')` only, so **a computed key is invisible to it**. Do not build
a key at runtime; map it explicitly.

## Escaping

Device names reach the page through `{!! !!}` so the link markup survives, which
makes the hand-written `e()` inside `$deviceLabel` load-bearing. Any markup that
touches it keeps that. A test asserts a hostname of `<script>alert(1)</script>`
comes back escaped.

## What we do not do

- No stylesheet, no script file, no font, no icon set, no CDN, no build step.
- No `tw:` class — it would emit no CSS.
- No literal colour.
- No focus ring, caret, scrollbar or selection theming. Those belong to the host
  and restyling them is exactly how a plugin starts out-dressing its house.
- No animation. Nothing on these surfaces is improved by motion.
- No icon that is not Font Awesome, already loaded. `fa-terminal` is the single
  mark; it resolves under the `fa` prefix in Font Awesome 4, 5 and 6 alike.
