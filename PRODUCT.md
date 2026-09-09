# Product

## Platform

web

## Users

Two confirmed audiences, both reaching WebTerm's surfaces through LibreNMS
itself. Neither is an on-call engineer working under incident pressure — that
was explicitly ruled out, and the design must not be optimised for it.

- **The setup operator.** Stands the plugin up: enables devices (or a static
  device group) for terminal access, stores a credential, writes the grants that
  let anyone connect at all. Visits are infrequent and deliberate — often once,
  then not again for months. They arrive not knowing the vocabulary (`flow`,
  `principal`, `host_key_policy`, `algorithm_profile`) and need the screen to
  explain what a field means and what writing it will do. Because visits are
  rare, nothing may rely on remembered state.
- **The security reviewer / auditor.** Reads Access, Host keys and Audit to
  answer one question: *who could open a shell on what, and who did.* They are
  not administering; they are reading evidence. The answer must be legible on
  the page, without cross-checking against `webterm:why` on the CLI.

Both hold WebTerm's own `admin` (or `audit.view`) ability, which is deliberately
separate from being a LibreNMS administrator. The population is small by design
— "as few accounts as you would trust with `sudo` on the LibreNMS host".

The **device-page terminal surfaces** (the Terminal tab, the overview panel) have
a third, much larger audience: any LibreNMS user holding a grant. They are doing
network work on a device, not administering WebTerm, and they reach the terminal
in the middle of that work.

## Product Purpose

WebTerm is an in-browser SSH terminal for LibreNMS devices, opened from the
device page — replacing the `ssh://` link that hands the operator off to a local
client. Credentials come from HashiCorp Vault (short-lived signed SSH
certificates or KV v2) or from encrypted rows in the LibreNMS database.

It succeeds when an operator can open a shell on a monitored device from the
device page, and when the organisation can answer afterwards exactly who did,
to what, and under whose authority.

## Positioning

The plugin never opens an SSH connection and the gateway never calls back into
LibreNMS. Authorization, credential resolution and ticket minting happen in PHP;
terminating the WebSocket and dialling SSH happens in a separate static Go binary
that holds no database credentials, no Vault token and no LibreNMS session. The
plugin always initiates, so there is no credential-vending endpoint on the public
vhost, and the only wire carrying secret material is loopback.

The honest framing, stated in the README rather than buried: this turns the
monitoring system — which has the broadest ACL in most organisations and is a
public-facing PHP application — into a jump host. Defaults are closed and the
threat model is published. That candour is the position.

## Operating Context

- **Everything renders inside LibreNMS.** The admin console, the device tab and
  the overview panel extend `layouts.librenmsv1` (Bootstrap 3). jQuery, select2
  and Bootstrap 3 are already loaded by that layout. The surfaces are guests in
  someone else's house and must read as part of it.
- **The console is one route with seven tabs** selected by `?tab=`:
  targets, credentials, access, hostkeys, sessions, audit, settings. A tab switch
  is a full page load, not a client-side toggle.
- **A realistic install has hundreds of enabled targets** — whole-fleet
  enablement via static device groups is a first-class path. Targets and Audit
  therefore get long. The controller does not paginate (Targets and Grants are
  unbounded; Sessions is capped at 50, Audit at 100), so scanning a long table is
  the real usage scene.
- **The console is deliberately not the whole surface.** Host key mutation and
  several settings stay on the CLI on purpose, and the console shows them
  read-only *with the reason*. An operator hunting for a control should find it
  and learn why it is not theirs to change here.
- **The CLI is the other half of the product** and is never behind. Every
  console action has a `webterm:*` equivalent, and an install can grant nobody
  the `admin` ability and be administered entirely from a shell.
- **Devices are chosen by name**, through LibreNMS's own `init_select2` device
  picker, which filters by the requesting user's device visibility.
- **Some installs have no scheduler.** Without LibreNMS's separately-installed
  scheduler cron/timer, sessions sit at `pending` forever and nothing reaps them.
  The surface has to be able to say so.
- **The terminal is an iframe** served by the gateway; the single-use ticket
  crosses by `postMessage`, never in a URL, because a query string reaches the
  proxy access log and leaks through `Referer`.

## Capabilities and Constraints

Confirmed, and binding on any future work:

- **No new dependencies of any kind.** No npm, no build step, no CSS framework,
  no CDN asset. `tools/composer-guard.php` fails CI if the composer require list
  changes. The runtime requirements are PHP and `librenms/plugin-interfaces`.
  jQuery, select2 and Bootstrap 3 — supplied by the host layout — are the only
  libraries available.
- **`sh tools/preflight.sh` must exit 0.** It runs composer validate, the
  dependency guard, the docs check, Pint, PHPStan, Pest, protocol codegen
  comparison and the Go gateway's fmt/vet/test.
- **Device names are rendered with `{!! !!}`** so the link markup survives, which
  makes hand-escaping with `e()` load-bearing. A test asserts a hostname of
  `<script>alert(1)</script>` comes back escaped.
- **No lowercase single-word `__()` keys.** `__('device')` resolves to LibreNMS's
  `lang/en/device.php` and returns an array, which is fatal inside `{{ }}`. A
  guard test enforces this. Use a phrase or a capitalised label.
- **Stored values are not translated.** Enum values an operator matches against
  CLI output and database rows (`database`, `ssh_signer`, `kv2`, `private_key`,
  `pin`, `tofu_first_connect`, `allow`, `deny`, `use`, `admin`, `audit.view`) are
  rendered verbatim; translating them would misdescribe what is written.
- **Nothing renders a secret.** No credential payload, shared secret, ticket or
  host key blob reaches any page. Credential fields are write-only, and the
  failure path never calls `withInput()`.
- **The plugin settings page cannot configure anything.** LibreNMS stores the
  plugin settings bag as plaintext JSON, so that page is status and signposting
  only.
- **Only static device groups can be enabled** by default, because LibreNMS
  recomputes dynamic membership on every poll. An operator may allow dynamic
  groups deliberately, and the copy must change to say what that trade means.
- **Read-only by design, in the console:** host key pins and policy; the
  gateway's addresses and shared-secret path; the credential encryption key; the
  credential driver; the gateway-owned allowed origins.
- **`layouts.librenmsv1` may be absent.** Every view falls back to
  `WebTerm::layouts.standalone` via `@extendsFirst`, and `<x-device.page>` is
  reached only through a separately-included partial, because Blade resolves
  components at compile time. This is what makes the views testable without
  LibreNMS present, and it must keep working.

## Brand Commitments

- The name is **WebTerm**; the package is `adaptivedatanetworks/librenms-webterm`.
  Not affiliated with or endorsed by LibreNMS or HashiCorp, and the surfaces must
  not imply otherwise.
- **Voice, established by `docs/contributing/style-guide.md` and matched by the
  code comments:** address the reader as *you*; imperative for instructions; be
  plain about limitations and say *why*. "A documentation set that admits its
  gaps is trusted; one that quietly omits them is not." The existing UI copy
  already carries this — a control that appears to work and does nothing is
  worse than no control; read-only *on purpose*, with the reason given. That
  candour is the product's voice and refinement must preserve it.
- Any sentence of the form "X cannot happen" must be traceable to a named test.
- The single visual mark in use is Font Awesome's `fa-terminal`, from the host
  layout's icon font.
- Licensed GPL-3.0-or-later, matching LibreNMS.

## Evidence on Hand

- Real product documentation at `docs/` (39 pages, built with mkdocs strict) and
  a published threat model. `docs/operate/console.md` documents the console
  tab-by-tab and is the authority on what each tab is for.
- Real setting labels, help text and read-only reasons in
  `src/Support/EditableSettings.php` — already written, already good, and the
  console renders them verbatim.
- A test suite (Pest) including the XSS-escaping and translation-key guards.
- **No screenshots, visual-regression goldens or design artefacts exist**, and
  the plugin cannot be run outside a LibreNMS installation. Visual truth comes
  from reading the Blade against LibreNMS core's own views. A LibreNMS core
  checkout may be present at `/tmp/claude-1000/lnms-pr/librenms` for comparison.
- **Undecided / absent:** no logo, no wordmark, no colour palette of its own, no
  usage metrics, no user research beyond the audiences recorded above.

## Product Principles

1. **Closed by default, and say so.** Nothing opens a shell until somebody
   decided it should. The surface states what is off, not just what is on.
2. **Admit the gaps.** A missing control is shown with its reason rather than
   hidden. Naming a limitation is the product working, not the product failing.
3. **Never render a secret.** Fields that take one are write-only; a stored one
   is never read back to any page.
4. **A guest in LibreNMS's house.** Match the host's panels, tables and buttons.
   Belonging beats standing out — and beats looking better than the host.
5. **The CLI is never behind.** Every console action has a command equivalent,
   and an install may choose the shell as its only administrative path.
6. **Written for someone who will not be back for months.** Vocabulary is
   explained where it is used; nothing depends on remembered state.

## Accessibility & Inclusion

No project-specific standard: WebTerm inherits whatever LibreNMS core provides
and must not regress it. Practically that means the host's Bootstrap 3 semantics
are used as intended — real `<th>` headers in a real `<thead>`, labels tied to
their controls, focus left alone, no meaning carried by colour alone — without
claiming a conformance level the surrounding LibreNMS page could not back.
