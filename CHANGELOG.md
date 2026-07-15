# Changelog

All notable changes to **reportbrokenlink** are documented here.

---

## 4.0.0 — 2026-07-15

A rewrite. The module now stores reports instead of only mailing them, adds a back-office
dashboard, and fixes two critical security holes and a bug that had been silently discarding
every report since 3.1.0. Full findings in `AUDIT.md`; upgrade details in `MIGRATION.md`.

### Fixed — critical

- **Reports were never delivered, and customers were told they were.** The mail template path
  (`dirname(__FILE__) . '/mails/'`) was left unchanged when the endpoint moved from the module
  root into `controllers/front/` in 3.1.0, so it resolved to
  `modules/reportbrokenlink/controllers/front/mails/` — a directory that does not exist.
  `Mail::Send()` returned false and the module ran `die('0')`; `0` is valid JSON, so jQuery
  parsed it and called the **success** handler. Every shop on 3.1.0–3.2.2 has been discarding
  reports while thanking the reporter. Mail paths now resolve from `_PS_MODULE_DIR_`, and
  reports are stored before any mail is attempted, so a mail failure can no longer lose one.
- **Open mail relay.** The notification recipient was read from a hidden `shop_email` field in
  the page and passed straight to `Mail::Send()` as `$to`, unvalidated. Anyone could make the
  shop mail any address, with unescaped attacker-supplied HTML in the body, unthrottled. The
  recipient now comes from module configuration and nowhere else.
- **`secure_key` was not a CSRF token.** It was `md5(_COOKIE_KEY_ . 'reportbrokenlink')` —
  constant for the shop's lifetime and printed into every product page. Read once, replayable
  forever. Replaced with an HMAC-signed, time-limited token bound to the product id.

### Fixed — security

- Visitor input is no longer injected unescaped into the notification e-mail. PrestaShop
  substitutes mail variables with a plain `str_replace` and escapes nothing, so a reporter could
  put a phishing link — or a fake form — into an e-mail sent by the merchant's own shop. Input
  is now stripped of markup on the way in *and* escaped on the way out.
- Removed jQuery 1.8.2, loaded from `ajax.googleapis.com` on **every product page**. It sent
  every visitor's IP to Google before any consent (GDPR), shipped a 2012 library affected by
  CVE-2020-11022 / CVE-2020-11023 / CVE-2019-11358 / CVE-2015-9251, and overwrote the theme's
  own jQuery for everything that ran after it. The module now uses vanilla JS and needs no
  jQuery at all.
- Added rate limiting: configurable per visitor per product per hour (default 1), plus a fixed
  global cap of 10/hour/visitor. The reporter's IP is **not** stored — only a keyed
  `hash_hmac('sha256', $ip, _COOKIE_KEY_)`.
- Added a honeypot field and a minimum fill time, so instant bot submissions are rejected.
- Submissions are now `POST`. 3.x sent them as `GET` — `$.ajax` was passed `post: "POST"`, which
  is not a jQuery option, so the default GET was used — putting every reporter's e-mail address
  into the web server access logs.
- The product a report is filed against is now checked to exist, be active, and belong to the
  current shop.
- `From:` is now the shop's address, with the reporter in `Reply-To`. 3.x put the customer's
  address in `From:`, which fails SPF/DKIM alignment and gets notifications spam-foldered.
- CSV export escapes cells beginning with `=`, `+`, `-` or `@`, so a reporter cannot smuggle a
  spreadsheet formula into a file the merchant opens.
- The export runs inside the token-protected configure page rather than a public endpoint.

### Fixed — behaviour

- Client-side validation that could never fail. `if (datas.length >= 3)` counted three hidden
  fields that were always present, so *"You did not fill required fields"* was unreachable and
  empty reports were mailed with seven blank bullet points.
- `install()` on PrestaShop exactly 1.7.0.0. The check was
  `version_compare(_PS_VERSION_, '1.7.0.0 ', '>')` — trailing space, and `>` instead of `>=` —
  so 1.7.0.0 registered `extraLeft`, a 1.6-only hook, and the button never appeared.
- `uninstall()` could report failure after succeeding: it called `unregisterHook()` *after*
  `parent::uninstall()` had already deleted the module row.
- The product is read from the hook's parameters instead of `Tools::getValue('id_product')`,
  which silently produced product `0` wherever the hook rendered without it in the query string.
- `$('.close').trigger('click')` no longer fires — it dismissed **every** element with class
  `.close` on the page, including the theme's own alerts and modals.
- The modal is moved to `<body>`; rendered in place it sat inside the theme's add-to-cart
  `<form>`. The `.modal-header .close { margin-top: -25px }` hack that papered over this is gone.
- Removed the undeclared dependency on Bootstrap 4 JS (`data-toggle="modal"`) and the Material
  Icons font, which only exist in the classic theme — on custom themes the button rendered as a
  tofu box and did nothing.
- The stylesheet is registered through `actionFrontControllerSetMedia` instead of being emitted
  as a `<link>` in the middle of `<body>` on every render.
- `Tools::displayDate()` is called correctly on both sides of the PrestaShop 8 signature change,
  which had dropped `$id_lang` and `$separator`.
- Report reasons are no longer duplicated between the template and the controller and matched by
  `checkbox1..7` index, where any mismatch silently mailed the wrong text.
- Checkbox 4 was hidden for non-virtual products while checkbox 5 ("the download file is
  missing") was shown on every physical product.

### Added

- **Storage.** New table `PREFIX_reportbrokenlink_report`, indexed for the list, the stats and
  the rate limiter. Nothing was stored before.
- **Reports dashboard** on the configure page: filter by status, issue type, category, date
  range and free text; bulk mark in progress / resolved / spam / delete; expandable rows with
  the full message, product and customer links, status selector, notes/reply field and
  timestamps; pagination.
- **Statuses**: open, in progress, resolved, duplicate, spam.
- **Configuration page** — the module had none. Recipients, button on/off, button position,
  which issue types to offer, guest reports, rate limit, deduplication, customer notification,
  reCAPTCHA keys, rows per page.
- **Health checks** on the configure page: real checks against live configuration rather than a
  list of instructions.
- **Deduplication**: the same person reporting the same issue on the same product within 24h is
  filed as *Duplicate* instead of alerting the merchant twice.
- **Customer resolution e-mail** (`report_resolved`, EN + TR): one tick box when resolving a
  report tells the reporter you fixed it. Only sent if they left an address.
- **CSV export** of exactly the filtered set.
- **Dashboard widget** showing waiting reports, which hides itself when there are none.
- **Stats**: reports this week / this month, and the five most-reported products.
- **Free-text message field** (10–1000 characters, live counter) and an issue-type dropdown,
  replacing the seven fixed checkboxes.
- **Accessibility**: real dialog semantics, focus trap, Escape to close, focus restoration,
  ARIA error announcements, visible focus rings, `prefers-reduced-motion`, mobile full-screen.
- **`displayGDPRConsent`** slot, rendered when the official `psgdpr` module is present.
- **Optional reCAPTCHA v3**, off by default, verified server-side.
- **`translations/tr.php`** — Turkish UI translations. 3.x shipped Turkish *mail* templates but
  an **empty** `translations/en.php`, so no interface string was ever translatable.
- **`tests/ValidatorTest.php`** — 47 assertions over the sanitising and validation rules,
  runnable without a shop: `php tests/ValidatorTest.php`.
- `Readme.md` with a version support matrix, troubleshooting, e-mail template variables and
  developer notes. `AUDIT.md` and `MIGRATION.md`.

### Changed

- Renamed to **"Report an Issue - Customer Product Page Feedback"**.
- Minimum PrestaShop version is now 1.7.0. The dead `extraLeft` branch is gone.
- Button position is configurable (`displayProductAdditionalInfo` or `displayFooterProduct`).
  The former stays registered on upgrade, so the button does not move on existing shops.
- AFL 3.0 license headers, house code style throughout.

### Removed

- `controllers/front/ajax.php` — the open mail relay. Deleted from disk on upgrade, since
  PrestaShop extracts over the old folder and it would stay routable otherwise.
- `reportbrokenlink_ajax.php` (3.0.0's endpoint, which bootstrapped the shop itself via
  `config.inc.php`), also deleted on upgrade.
- `views/templates/front/reportbrokenlink-extra.tpl`, `views/css/reportbrokenlink.css`,
  `views/img/` (`loading.gif`, `broken-link.gif`, `reportbrokenlink.png` — the last two were
  never referenced), and `logo.gif` (a PrestaShop 1.4-era icon, unused since 1.5).
- The dead `jsonDecode()` method, and the `hookproductactions()` / `hookproductbuttons()`
  handlers, which were defined but never registered and so had never once fired.
- The deprecated `Tools::encrypt()` call and the 1.4-era `__construct($dont_translate)` signature.

### Known limitations

- Verified by source analysis, static checks and the unit suite. A live end-to-end pass on
  1.7.0 / 1.7.8 / 8.x / 9.x is still worth doing before release — see `MIGRATION.md`.

---

## 3.2.2 and earlier

Not documented. See `git log`. Note that 3.1.0, 3.2.0, 3.2.1 and 3.2.2 all shipped with the
broken mail path described above, and none of them ever delivered a report.
