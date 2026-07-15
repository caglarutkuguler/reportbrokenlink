# Report Broken Links — Phase 1 Audit

Audited version: **3.2.2** (working tree, `beta/reportbrokenlink`)
Date: 2026-07-15
Auditor: refactor pass, MEG Venture module modernization programme

---

## 1. Executive summary

The module is small (4 code files, ~385 lines) and has **no `/override` directory** — one success
criterion is already met before we start.

The headline finding is not a style issue:

> **Reports have not been delivered to anyone since v3.1.0, and the customer is always told
> "Your report has been successfully sent to our team."**

Two independent defects combine to produce this (F1 + F2 below). The mail template path broke when
the AJAX endpoint moved from the module root into `controllers/front/` in v3.1.0 and the
`dirname(__FILE__)` next to it was never adjusted; the front-end reports success regardless of what
the server answers. Every shop running 3.1.0–3.2.2 has been silently dropping customer reports.

Alongside that, the endpoint that sends the mail is **an unauthenticated open mail relay** (S1): the
recipient address is read from a hidden field in the page, so anyone can make the shop send mail to
any address of their choosing, with attacker-supplied HTML in the body (S4), as many times as they
like (S3).

Second structural finding: **the module has no database table, no admin page, and no configuration
of any kind.** It is a stateless mail form. Large parts of the brief (reports dashboard, statuses,
admin responses, CSV export, deduplication, rate limiting) describe a system that does not exist
yet and would be new construction rather than refactoring. See §9 for the scope reconciliation.

Counts: **9 security findings** (2 critical), **18 functional bugs**, **14 technical-debt items**.

---

## 2. Current structure

The working tree has already been restructured from the tracked layout (module was nested in a
`reportbrokenlink/` subfolder; it now sits at the repo root, uncommitted — consistent with the other
modules in the programme).

```
reportbrokenlink.php                                 81 lines   main class, no config page
controllers/front/ajax.php                          115 lines   report submission endpoint
views/templates/front/reportbrokenlink-extra.tpl    170 lines   button + modal + inline CSS link + inline JS + CDN jQuery
views/css/reportbrokenlink.css                       19 lines   3 rules, one of them a hack
views/img/loading.gif                                           spinner, used
views/img/broken-link.gif                                       unreferenced
views/img/reportbrokenlink.png                                  unreferenced
mails/en/report_broken_link.{html,txt}                          admin notification
mails/tr/report_broken_link.{html,txt}                          admin notification (Turkish)
translations/en.php                                   4 lines   EMPTY — $_MODULE = array()
logo.gif                                                        PS 1.4-era, unused since 1.5
logo.png                                                        used
index.php × 9                                                   directory stubs (keep)
```

**Missing entirely:** `getContent()` / configuration page, database schema, `upgrade/`, `Readme.md`
at module root (the 2-line README lives one level up and is deleted in the working tree),
`LICENSE.txt`, `CHANGELOG.md`, `.gitignore`, `translations/tr.php`.

**Dead code / files:** `views/img/broken-link.gif`, `views/img/reportbrokenlink.png`, `logo.gif`,
`ReportBrokenLinkAjaxModuleFrontController::jsonDecode()` (never called, already marked
`@deprecated`), `hookproductactions()`, `hookproductbuttons()` (defined but never registered — see
F6), `translations/en.php` (present but empty, so it does nothing).

---

## 3. Overrides

**None.** There is no `/override` directory in any shipped version (v3.0.0 through v3.2.2) or in the
working tree. Phase 2's "override elimination" is a no-op; the "zero overrides" criterion is met.

The nearest thing to an override was v3.0.0's `reportbrokenlink_ajax.php`, a raw PHP endpoint that
bootstrapped the shop itself:

```php
require_once(dirname(__FILE__).'/../../config/config.inc.php');
require_once(dirname(__FILE__).'/../../init.php');
```

That was correctly replaced by a proper `ModuleFrontController` in v3.1.0. That change was the right
call and is the direct cause of F1 (the mail path was not updated to match the new file location).

---

## 4. Security findings

| # | Severity | Finding |
|---|----------|---------|
| S1 | **Critical** | Attacker-controlled mail recipient — open relay |
| S2 | **Critical** | `secure_key` is not a CSRF token; endpoint is effectively unauthenticated |
| S3 | High | No rate limiting on an endpoint that sends mail |
| S4 | High | Unescaped visitor input injected into the merchant's e-mail (HTML/phishing) |
| S5 | Medium | Third-party jQuery 1.8.2 from Google CDN on every product page |
| S6 | Medium | `id_product` never validated as a real, visible product |
| S7 | Low | `From:` header spoofs the customer's address (SPF/DKIM failure) |
| S8 | Low | No consent notice / privacy statement on an e-mail-collecting form |
| S9 | Info | No stored XSS surface (reports are never displayed anywhere) |

### S1 — Critical: attacker-controlled mail recipient (open mail relay)

`controllers/front/ajax.php:32` reads the destination address out of the submitted payload:

```php
} elseif ($entry->key == 'shop_email') {
    $report_mail = $entry->value;
```

which arrives from a hidden input the client fully controls
(`views/templates/front/reportbrokenlink-extra.tpl:48`), and hands it straight to `Mail::Send()` as
the `$to` argument (`ajax.php:82`). It is never compared against `Configuration::get('PS_SHOP_EMAIL')`
and never passed through `Validate::isEmail()`.

Any unauthenticated visitor can POST an arbitrary `shop_email` and make the shop's SMTP server
deliver mail to any address. Combined with S4 (attacker-supplied HTML in the body) and S3 (no rate
limit), this is a spam cannon pointed at the merchant's sending reputation and IP.

The recipient must come from server-side configuration only. The client must never name the
recipient.

### S2 — Critical: `secure_key` is not a CSRF token

`reportbrokenlink.php:25` sets `$this->secure_key = Tools::encrypt($this->name)`, i.e.
`md5(_COOKIE_KEY_ . 'reportbrokenlink')`. This value is:

- **constant** for the entire lifetime of the shop — it never rotates;
- **not bound to a session, customer, or request**;
- **printed into the HTML of every product page** (`tpl:148`, `secure_key: '{$rpl_secure_key}'`).

Anyone who loads one product page once can read it and replay it forever, from anywhere. It provides
no CSRF protection and no authentication — it is a shared secret published to the public. The check
at `ajax.php:22` is decorative.

Replace with a real per-session token (`Tools::getToken()` / `Tools::getValue('token')` validated
server-side), and note that this alone does not solve S1 — a valid token still must not let the
client choose the recipient.

### S3 — High: no rate limiting

One unauthenticated request produces one e-mail, with no throttle by IP, session, product, or
customer. A trivial loop floods the merchant's inbox and gets the shop's sending domain blacklisted.

The brief asks for max 1 report per IP per hour per product. Note the tension with the "don't store
customer IP" anti-pattern — see §9.4 for the resolution.

### S4 — High: unescaped visitor input in the merchant's e-mail

`$visitoremail` is taken raw from the form (`ajax.php:37`), placed into `{customeremail}`
(`ajax.php:65`), and substituted into `mails/en/report_broken_link.html:56` by simple string
replacement. PrestaShop does **not** escape legacy mail template variables.

An attacker submits `<a href="https://evil/">Click to review this report</a>` (or a full fake login
form) as their "e-mail address", and the merchant opens it in their mail client as part of a mail
that genuinely originates from their own shop. This is a phishing vector aimed at the shop owner,
delivered with the shop's own credibility.

All template variables must be escaped for the target format before substitution.

### S5 — Medium: third-party jQuery 1.8.2 from Google CDN

`tpl:126` unconditionally injects, on every product page:

```html
<script src="https://ajax.googleapis.com/ajax/libs/jquery/1.8.2/jquery.min.js"></script>
```

Three separate problems:

1. **Privacy/GDPR** — every product page view sends the visitor's IP and referrer to Google before
   any consent is collected. For an EU merchant this is the exact pattern the German courts have
   ruled on for Google Fonts.
2. **Known vulnerabilities** — jQuery 1.8.2 is from 2012 and is affected by CVE-2020-11022 /
   CVE-2020-11023 (XSS via `.html()` / `htmlPrefilter`, all versions `>=1.2 <3.5.0`),
   CVE-2019-11358 (prototype pollution, `<3.4.0`) and CVE-2015-9251 (`<3.0.0`).
3. **It clobbers the theme's jQuery** — it assigns `window.jQuery` / `window.$` globally with no
   `noConflict()`, replacing the jQuery 2/3 that PS 1.7+ ships, for everything that runs afterwards.
   This can break the theme's and other modules' scripts on product pages only, which is a
   miserable bug to diagnose.

The module needs no jQuery at all; the whole script is ~40 lines of vanilla-equivalent work.

### S6 — Medium: `id_product` never validated

`ajax.php:56` does `new Product((int) $id_product, ...)` with no `Validate::isLoadedObject()` check,
no active check, and no shop-visibility check. The `(int)` cast does close the SQL-injection door
(and no other user value reaches SQL — the module runs no queries of its own), but reports are
accepted for products that do not exist, are disabled, or belong to another shop. `getProductLink()`
on an unloaded product yields a broken link in the merchant's e-mail.

### S7 — Low: `From:` header spoofs the customer

`ajax.php:85-87` passes the logged-in customer's own address as `Mail::Send()`'s `$from`:

```php
($ReportBrokenLink->context->cookie->email ? $ReportBrokenLink->context->cookie->email : null),
```

The shop's SMTP server then sends mail claiming to be `From: customer@gmail.com`. That fails SPF and
DKIM alignment at the receiving end, so these notifications get spam-foldered or rejected outright —
on the shops where the mail is not already broken by F1. The shop address belongs in `From:`; the
customer belongs in `Reply-To:`.

### S8 — Low: no consent notice on an e-mail-collecting form

The form invites an e-mail address ("we will let you know about the fix") with no privacy notice and
no consent checkbox. For GDPR, collecting a contact address for a stated purpose needs at minimum a
statement of what happens to it. A `displayGDPRConsent` hook slot (as used in the `popup` module)
is the house solution.

### S9 — Info: no stored XSS

Reports are never persisted or rendered anywhere, so there is no stored XSS surface today.
**This changes the moment Phase 2 adds a reports dashboard** — visitor-submitted text rendered in the
admin becomes the module's primary XSS risk, and every field must be escaped at output.

---

## 5. Functional bugs

### F1 — Critical: mail template path is wrong; reports are never sent

`ajax.php:90` passes:

```php
dirname(__FILE__) . '/mails/'
```

from `controllers/front/ajax.php`, which resolves to:

```
modules/reportbrokenlink/controllers/front/mails/     ← does not exist
```

The templates live at `modules/reportbrokenlink/mails/`. `Mail::Send()` checks the template file
exists under the supplied path, logs *"Error - The following e-mail template is missing"*, and
returns `false` — so no mail is ever sent, on any shop, in any language.

**Confirmed regression, introduced in v3.1.0.** In v3.0.0 the endpoint was `reportbrokenlink_ajax.php`
at the module root, where `dirname(__FILE__).'/mails/'` was correct. v3.1.0 moved it to
`controllers/front/ajax.php` and copied the line verbatim. Present in 3.1.0, 3.2.0, 3.2.1, 3.2.2.

*Verification note: confirmed by file-path reasoning against the shipped zips; worth one live
confirmation on a test shop during Phase 2 (submit a report, check the mail log).*

### F2 — Critical: the customer is told "success" no matter what happens

Three layers conspire:

1. When `Mail::Send()` fails, `ajax.php:92` runs `die('0')` — which emits `0`. That is **valid JSON**,
   so jQuery's `dataType: "json"` parses it happily and calls **`success`**, not `error`.
2. When the `secure_key` check fails, `save()` falls through and returns `true` → `success`.
3. `tpl:152-160`'s `success` handler unconditionally shows *"Your report has been successfully sent
   to our team. Thank you!"* without inspecting `result` at all.

So the customer is thanked for a report that was silently discarded. This is what has kept F1
invisible since v3.1.0.

### F3 — The client-side validation can never fail

`tpl:141`: `if (datas.length >= 3)`.

The collector at `tpl:133` walks **every** `input` in the modal, which always includes
`virtual_product_name`, `shop_email` and `id_product` — three entries, before the visitor touches
anything. The condition is therefore always true and *"You did not fill required fields"* is
unreachable. Empty reports (no box ticked, no message) are submitted and mailed with all seven
`{reportN}` slots blank.

Compounding it: `$(this).val()` returns `"on"` for an unchecked checkbox with no `value` attribute,
so the `if (o.value != '')` filter does not exclude unticked boxes either — every checkbox is always
pushed, and only the separate `checked` flag distinguishes them server-side.

### F4 — The AJAX request is sent as GET

`tpl:145`: `post: "POST"`. There is no `post` option in jQuery's `$.ajax` — the option is `type` (or
`method`). The typo means the setting is ignored and **the default GET is used**.

Consequences: the entire JSON payload rides in the query string; a state-changing, mail-sending
operation is performed over GET (prefetchable, CSRF-friendly, cacheable by proxies); and every report
— including the visitor's e-mail address — is written into the web server's access logs in plain
text, which is its own GDPR problem.

### F5 — `install()` is broken on PrestaShop exactly 1.7.0.0

`reportbrokenlink.php:36`:

```php
if (version_compare(_PS_VERSION_, '1.7.0.0 ', '>')) {
```

Note the **trailing space** inside the version string and the **`>`** rather than `>=`. On PS
1.7.0.0 exactly, the comparison is false, so the module registers `extraLeft` — a hook that does not
exist in 1.7 — and the button never appears. PS 1.7.0.x is explicitly in the target support matrix.

### F6 — `hookproductactions()` / `hookproductbuttons()` are dead code

Both are defined (`reportbrokenlink.php:67-75`) and neither is ever registered — `install()` only
registers `displayProductAdditionalInfo` or `extraLeft`. They are inherited from v3.0.0 and have
never fired. (`productButtons`/`productActions` are 1.6-era names in any case.)

### F7 — `uninstall()` can report failure after succeeding

`reportbrokenlink.php:45-49`:

```php
return parent::uninstall() && $this->unregisterHook('displayProductAdditionalInfo');
```

`parent::uninstall()` already removes the module's hook registrations and deletes the module row.
The subsequent `unregisterHook()` then operates on a module that no longer exists and can return
false, making a successful uninstall report as failed in the back office.

### F8 — The product is read from the URL, not from the hook

`reportbrokenlink.php:55` uses `Tools::getValue('id_product')` instead of the `$params['product']`
that `displayProductAdditionalInfo` provides. It happens to work on the standard product page, but it
is fragile by construction and yields a `Product` with id `0` anywhere the hook is rendered without
`id_product` in the query string.

### F9 — `$('.close').trigger('click')` closes every modal on the page

`tpl:156` fires a click on **all** elements with class `.close` five seconds after submission — a
class Bootstrap uses for every dismissible alert and modal in the theme. The module reaches outside
its own DOM and dismisses other components' UI.

### F10 — Undeclared Bootstrap and Material Icons dependency

`data-toggle="modal"` / `data-target` (`tpl:18`) require Bootstrap 4's JS, and
`<i class="material-icons">link_off</i>` requires the Material Icons font. Both happen to be present
in PS 1.7's *classic* theme and are absent from many custom themes, where the button renders as a
tofu box that does nothing when clicked. Nothing declares or checks this.

### F11 — Stylesheet injected in `<body>`, per render

`tpl:17` emits a `<link rel="stylesheet">` inside the hook output, i.e. in the middle of the body.
Invalid placement, unnecessary render-blocking, no cache-busting, and it bypasses PS's asset
pipeline. Belongs in `actionFrontControllerSetMedia` (house style).

### F12 — The modal markup is emitted inside the product page's DOM

`displayProductAdditionalInfo` renders inside the product's `<form>` in the classic theme. A
Bootstrap `.modal` nested inside a form is a known source of layout and submission oddities; modals
belong at body level. Related: `views/css/reportbrokenlink.css:17-19` carries a
`.modal-header .close { margin-top: -25px }` hack that is a symptom of exactly this.

### F13 — Success/error UI is inconsistent

`#report_link_form_error` is only ever populated by the unreachable branch (F3) and is never cleared
between attempts. `#conf` fades in on "success" and the modal auto-closes 5s later on a fixed timer,
racing the 3s fade. The `error` handler only does `console.log(jqXHR)` — a network failure shows the
visitor nothing at all.

### F14 — Checkbox 4 is gated on virtual products, checkbox 5 is not

`tpl:73` wraps *"I purchased the virtual product, but not received the download file"* in
`{if $virtual<>0}`, but *"The download file is missing or download link is not working"* (`tpl:86`)
is shown unconditionally — including on purely physical products, where it is nonsense.

### F15 — The seven report reasons are duplicated and index-coupled

The reason strings exist twice — once as labels in the `.tpl` (lines 56-100) and once as
`$this->l()` calls in `ajax.php:39-51` — and are matched by the `checkbox1..checkbox7` naming
convention. Adding, removing or reordering a reason means editing two files in lockstep, and any
mismatch silently mails the wrong reason text. `$report1 = $report2 = ... = $report7 = ''` (`ajax.php:28`)
is the tell.

### F16 — The e-mail always renders seven bullet points

`mails/en/report_broken_link.html:34-54` hardcodes seven `- {reportN}` rows. Unticked reasons
substitute to an empty string, so the merchant receives a list of empty bullets with the one real
report somewhere among them.

### F17 — Work is done in the controller's constructor

`ajax.php:9-17` does the entire job — `save()`, `echo`, `exit()` — inside `__construct()`, before
PrestaShop's dispatcher has run `init()` / `postProcess()`. It also bypasses maintenance mode and SSL
handling, never sets `$this->ajax = true`, and the `Content-Type: application/json` header is
commented out (`ajax.php:13`), so JSON is served as `text/html`. The correct seam is
`initContent()` / `displayAjax()`.

### F18 — Redundant module instantiation

`ajax.php:21` does `new ReportBrokenLink()` when `ModuleFrontController` has already resolved the
module into `$this->module`. A second instance is constructed on every request purely to read
`secure_key` and call `l()`.

---

## 6. Technical debt inventory

**Deprecated / legacy APIs**

| Item | Status | Location |
|---|---|---|
| `Tools::encrypt()` | Deprecated since 1.7.0 (`Tools::hash()`) | `reportbrokenlink.php:25` |
| `extraLeft` hook | 1.4–1.6 only; removed in 1.7 | `reportbrokenlink.php:39,48` |
| `productActions` / `productButtons` | 1.6-era names | `reportbrokenlink.php:67,72` |
| `__construct($dont_translate)` | 1.4-era pattern | `reportbrokenlink.php:18` |
| `jsonDecode()` | Self-declared `@deprecated`, never called | `ajax.php:111` |
| `Mail::l()` in `sprintf()` | Wrong translation domain for a module subject | `ajax.php:78` |
| `$this->l($s, 'reportbrokenlink_ajax')` | Second arg + empty `en.php` = never resolves | `ajax.php:39-51` |

**Hardcoded values**

- Recipient: `PS_SHOP_EMAIL` — but read *via the client* (S1). Not configurable.
- The 7 report reasons: hardcoded twice (F15) and in the mail template (F16).
- Report types, statuses: do not exist.
- Module version `3.2.2` is duplicated in the `.tpl` header comment (`tpl:5`) and will drift.
- `data-target="#report_link_form"`, element IDs, and the `1.8.2` CDN URL are all inline.

**Missing modernization**

- No type hints anywhere (note: house style targets `array()` syntax and no scalar hints for PS 1.5
  compatibility — see §9.2 before applying the brief's PHP 7.2+ requirement).
- No `bootstrap = true`, no `ps_versions_compliancy` on the module class.
- `public $context;` (`reportbrokenlink.php:16`) redundantly redeclares `Module::$context`.
- `private $html` / `private $post_errors` (`:12-14`) are declared but **never used** — vestigial
  copies of the house config-page pattern for a config page that was never written.
- License header says "All rights reserved"; house style is the AFL 3.0 short header. No `LICENSE.txt`.
- Indentation is mixed tabs and spaces within the same file (`.tpl` especially).

**Database schema issues**

There is no schema. Nothing is stored. Every item under the brief's "Database" heading — table
structure, indexing, foreign keys to product/shop/customer, UTF-8 collation, statuses, timestamps —
is new construction, not remediation.

---

## 7. Compatibility (PS 1.7 → PS 9)

### Hook mapping

| Hook | Availability | Module's use |
|---|---|---|
| `displayProductAdditionalInfo` | **1.7 → 9** (classic `product.tpl`) | Registered on >1.7.0.0. Correct choice; keep it to preserve upgrades. |
| `extraLeft` | 1.4–1.6 only | Registered on ≤1.7.0.0 — including 1.7.0.0 itself (F5). Dead there. |
| `productActions`, `productButtons` | 1.6-era | Handlers defined, never registered (F6). |
| `actionFrontControllerSetMedia` | 1.7 → 9 | **Not used** — assets are inlined instead (F11, S5). |
| `displayGDPRConsent` | 1.7.6 → 9 | Not used (S8). |

*To verify on a live install rather than assert: whether `displayProductActions` exists as a core
hook in the 1.7/8/9 classic theme. `displayProductAdditionalInfo` is the safe primary and is already
what installed shops have registered — changing the primary hook would need an upgrade script and
buys little.*

### Breaking changes relevant to this module

- **PS 9 / PHP 8.x** — `Tools::encrypt()` is on the deprecation path; `dirname(__FILE__)` is fine but
  `__DIR__` is the modern form. Nothing here trips PHP 8's stricter type juggling *because* the
  module does so little; the empty-string comparisons in `ajax.php` are all string-to-string.
- **Symfony/Doctrine** — module Doctrine entity mapping and Symfony admin routing are practical from
  ~1.7.6+/8 and are **not** available in a form usable on 1.7.0. This directly conflicts with the
  brief; see §9.2.
- **Templates** — front office is Smarty `.tpl` in 1.7 → 9 (Twig is back office only). The brief's
  target structure lists `views/templates/front/*.html.twig`, which would not render on the front
  office of any PS version. See §9.3.
- **Mail** — legacy `mails/<iso>/<template>.{html,txt}` still works 1.7 → 9. PS 1.7.6+ added a
  Twig-based layout system, but the legacy path remains supported and is what the house modules use.

### Workflow test status

Submission workflow **could not be exercised** — no PrestaShop installation is available in this
environment. The findings above are from source analysis, cross-checked against the five shipped
release zips. F1 and F2 in particular deserve one live confirmation before release.

---

## 8. Database & data flow (as-is)

```
Visitor ticks boxes in modal
    │
    ▼
GET (not POST — F4) /index.php?fc=module&module=reportbrokenlink&controller=ajax
    ?action=ReportLink&secure_key=<shop-constant, public — S2>&report=<JSON>
    │
    ▼
ajax.php::__construct → save()
    │  secure_key compared against a constant published in the page (S2)
    │  recipient read from the payload (S1)
    │  reasons mapped from checkbox1..7 (F15)
    │  Product loaded, unvalidated (S6)
    │
    ▼
Mail::Send(to: client-supplied, from: the customer (S7), path: .../controllers/front/mails/ ✗ F1)
    │
    ├── template not found → false → die('0') → parses as JSON → success handler (F2)
    │
    ▼
Visitor sees "Your report has been successfully sent to our team. Thank you!"

Nothing is stored. No admin ever sees anything.
```

- **Storage:** none.
- **Admin queries:** none — there is no admin surface.
- **Notification flow:** one mail, to whoever the client asked for, currently to nobody (F1).
- **Status tracking:** none. No open/resolved/spam, no dedup, no history.

---

## 9. Scope reconciliation — decisions needed before Phase 2

The brief was written against an assumed module (DB-backed reports with a dashboard, overrides to
remove, Symfony forms, Doctrine). The actual module is a stateless mail form with no overrides. Four
points need a decision.

### 9.1 The brief is mostly new construction, not refactoring

Phases 3–4 describe: a reports table, an admin CRUD dashboard with filtering/pagination/bulk
actions, statuses, admin responses, CSV export, a dashboard widget, deduplication, rate limiting, and
customer resolution e-mails. None of it exists. This is a v4.0 product, roughly 3× the work of
fixing what is here.

The alternative — fix the security holes and the "reports go nowhere" bug, add a real config page,
drop the CDN jQuery, keep it e-mail-only — is a genuine v3.3/4.0 that ships far sooner and makes the
module *work*, which it currently does not.

### 9.2 Symfony forms + Doctrine vs. PS 1.7.0 support are mutually exclusive

The success criteria ask for both "tested & working on PS 1.7.0" and "admin form uses SymfonyForm".
A module cannot do both with one codebase: usable Symfony admin routing/forms and Doctrine mapping
for modules land well after 1.7.0, and PS 9 has moved again. Every other module in this programme
uses `HelperForm` + `ObjectModel` + Configuration, which works uniformly from 1.5 through 9 — that is
why the house style exists.

Recommendation: **HelperForm/ObjectModel**, consistent with `accountactivation`, `wecallyouback` et
al. It is the only option that satisfies the version matrix.

### 9.3 Twig front templates would not render

The target structure lists `views/templates/front/button.html.twig` and `modal.html.twig`. The PS
front office is Smarty on every version from 1.7 to 9; Twig is back-office only. These must stay
`.tpl`.

### 9.4 Rate limiting vs. "never store customer IP"

The brief asks for "max 1 report per IP/hour per product" and also lists "storing customer IP without
consent" as an anti-pattern. Both are satisfiable: store a **salted hash** of the IP
(`hash('sha256', _COOKIE_KEY_ . $ip)`) with a short retention window instead of the address itself.
That throttles effectively while storing no PII. Recommend that.

Also worth noting: several brief items are already satisfied and need no work — no `/override`
(§3), no stored XSS today (S9), no file uploads anywhere in the module, no SQL injection surface
(the module executes no queries at all).

---

## 10. Recommended Phase 2 priority

Regardless of which scope is chosen, these are non-negotiable and independent of it:

1. **F1** — fix the mail path. The module does not currently work at all.
2. **S1** — recipient from server config only. Open relay.
3. **S2** — real per-session CSRF token.
4. **F2** — report actual success/failure to the visitor.
5. **S4** — escape all mail template variables.
6. **S5** — delete the CDN jQuery; vanilla JS via `actionFrontControllerSetMedia`.
7. **S3** — rate limit (hashed IP, §9.4).
8. **F3/F4** — real validation; actual POST.
9. **F5** — fix the 1.7.0.0 version gate.

Then: config page (recipient, enabled reasons, rate limit, GDPR consent), and — scope permitting —
the storage layer and dashboard.
