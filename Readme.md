# Report an Issue — Customer Product Page Feedback

**reportbrokenlink** v4.0.0 — PrestaShop 1.7 → 9

Your customers find your broken pages before you do. This module gives them a discreet way to
tell you, and gives you one place to deal with it.

A small "Report an issue" button sits on every product page. A visitor picks what is wrong —
broken link, wrong price, missing information, something else — describes it in a sentence, and
optionally leaves an e-mail address. The report is **saved to your shop first** and e-mailed
second, so nothing is lost when your mail server has a bad day. You work through the list in the
back office, set each one to Resolved, and — with one tick box — let the reporter know you fixed
it.

---

## Version support matrix

| PrestaShop | Status | Notes |
|---|---|---|
| 1.7.0 – 1.7.5 | ✅ Supported | |
| 1.7.6 – 1.7.8 | ✅ Supported | `displayGDPRConsent` slot available if `psgdpr` is installed |
| 8.0 – 8.2 | ✅ Supported | `Tools::displayDate()` signature change handled internally |
| 9.x | ✅ Supported | |
| 1.6 and older | ❌ Not supported | Use 3.2.2, or upgrade your shop. See *Upgrading from 3.x*. |

| PHP | Status |
|---|---|
| 7.2 – 7.4 | ✅ Supported |
| 8.0 – 8.3 | ✅ Supported |

The module uses no Symfony forms and no Doctrine ORM. That is deliberate: neither is usable in a
module that must also run on 1.7.0, and the `HelperForm` + `Configuration` + direct-SQL approach
behaves identically from 1.7.0 through 9.x. See `MIGRATION.md` for the reasoning.

---

## Installation

1. Upload the `reportbrokenlink` folder to your shop's `modules/` directory (or install the zip
   from **Modules → Module Manager → Upload a module**).
2. Click **Install**.
3. Open **Configure** and set **Send new reports to** — this is the only setting that genuinely
   needs your attention.

The installer creates one table, `PREFIX_reportbrokenlink_report`, and registers five hooks.

---

## Configuration

Everything lives on the module's Configure page, under the reports list.

### 1. The button

| Setting | Default | What it does |
|---|---|---|
| Show the report button | On | Master switch. Turning it off hides the button and rejects submissions, but keeps every stored report. |
| Button position | Next to product information | `displayProductAdditionalInfo` (beside the description) or `displayFooterProduct` (below it). Try the other one if your theme hides the first. |
| Issue types visitors can pick | All four | Untick any you don't want offered. At least one is required. |
| Let visitors report without an account | Yes | With this off, the button only renders for signed-in customers. Guests are usually the people who notice broken pages, so leaving it on is recommended. |

### 2. Notifications

| Setting | Default | What it does |
|---|---|---|
| Send new reports to | Your shop e-mail | One or more addresses, comma-separated. **Reports are saved even if e-mail fails** — this address is a convenience, not the storage. |
| E-mail the customer when you resolve a report | Yes | Adds a tick box to each report when you set it to Resolved. Only ever sends if the reporter left an address. |

### 3. Spam protection

A honeypot field and an HMAC-signed form token are **always on** and need no configuration.

| Setting | Default | What it does |
|---|---|---|
| Reports per hour, per visitor, per product | 1 | `0` disables it. A global cap of 10 reports/hour/visitor always applies on top. |
| Flag repeat reports as duplicates | Yes | The same person reporting the same issue on the same product within 24h is filed as *Duplicate* instead of alerting you twice. |
| Use reCAPTCHA v3 | Off | Optional. Loads a Google script on your product pages — mention it in your privacy policy if you enable it. |

### 4. Back office

| Setting | Default | What it does |
|---|---|---|
| Reports per page | 20 | Between 5 and 300. |

---

## What the customer sees

A bordered, muted button — *"⚠ Report an issue with this page"* — that opens a modal dialog:

- **What is wrong?** — dropdown of the types you enabled
- **Describe the issue** — 10–1000 characters, with a live counter
- **Your name / Your e-mail** — both optional, shown to guests only (signed-in customers are
  identified from their account)
- GDPR consent block, if you have the official `psgdpr` module installed and enabled
- **Send report**, with a spinner, then a green confirmation panel

The dialog is keyboard navigable (Tab is trapped inside it, Escape closes it, focus returns to
the button), announces errors to screen readers, respects `prefers-reduced-motion`, and goes
full-screen below 480px.

## What you see

The Configure page has four panels:

1. **How it works** — a three-step explanation.
2. **Status** — live health checks, not instructions: is the button on, is a valid recipient
   configured, how many issue types are offered, is rate limiting active, is reCAPTCHA properly
   keyed. Problems show in red.
3. **At a glance** — waiting / this week / this month / resolved / spam counters (each clickable
   into a filtered list) and your five most-reported products.
4. **Reports** — the list.

The list filters by status, issue type, category, date range and free text across message, name
and e-mail. Bulk actions: mark in progress, mark resolved, mark as spam, delete. Each row
expands to show the full message, the product (with links to both the front page and the
product editor), the reporter, timestamps, a status selector and a notes/reply field.

**Export to CSV** exports exactly what the filters currently match.

A widget on the Dashboard home shows the count of waiting reports — and hides itself entirely
when there are none.

---

## E-mail templates

Four templates, in `mails/en/` and `mails/tr/`. Copy a folder to your language's ISO code to add
a language; PrestaShop picks the folder matching the recipient's language.

### `report_broken_link` — to you, when a report arrives

| Variable | Contains |
|---|---|
| `{reporter}` | The reporter's name, or "A visitor" |
| `{reporter_email}` | Their address, or "Not provided" |
| `{product}` | Product name |
| `{product_link}` | Front-office product URL |
| `{report_type}` | Translated issue type |
| `{message}` | What they wrote |
| `{report_date}` | When it arrived, in your shop's timezone |
| `{admin_link}` | Deep link to your Configure page |

Plus PrestaShop's own `{shop_name}`, `{shop_url}`, `{shop_logo}`.

The reporter's address goes in **Reply-To**, never in **From** — see *Troubleshooting*.

### `report_resolved` — to the reporter, when you resolve it

| Variable | Contains |
|---|---|
| `{reporter}` | Their name, or a neutral greeting |
| `{product}` | Product name |
| `{message}` | Their original report |
| `{admin_response}` | Your reply, or a neutral default if you left it blank |
| `{shop_name}` | Shop name |

**Every variable is HTML-escaped before substitution.** PrestaShop substitutes mail variables
with a plain `str_replace` and escapes nothing, so an unescaped visitor string would let a
reporter inject a phishing link into an e-mail that genuinely comes from your own shop. If you
edit these templates, keep the `white-space: pre-wrap` on the message blocks rather than
introducing `<br>` — that is what lets line breaks work without markup.

---

## Upgrading from 3.x

Install over the top. The module name, module key and the `displayProductAdditionalInfo`
registration are all preserved, so the button stays where merchants already expect it.

**There is no report data to migrate — 3.x stored nothing.** It only sent an e-mail, and from
3.1.0 onward it failed to even do that (see `AUDIT.md`). Your notification address is seeded
from `PS_SHOP_EMAIL`, which is where 3.x nominally sent reports.

The upgrade deletes the 3.x files from disk, because PrestaShop extracts a new module archive
*over* the old folder rather than replacing it. This matters: `controllers/front/ajax.php` stays
routable until something removes it, and it is an open mail relay.

⚠️ **The 3.x form changed shape.** The seven fixed checkboxes ("main product image not
displayed", "download file missing"…) are gone, replaced by an issue-type dropdown plus a
free-text message. The old checkboxes could only describe problems someone had anticipated; a
sentence covers the rest. Nothing is lost, because nothing was stored.

See `MIGRATION.md` for the full account.

---

## Troubleshooting

**The button doesn't appear.**
Check the Status panel first — the master switch may be off, or guest reports may be disabled
while you're browsing logged out. If the panel is all green, your theme may not render the hook:
switch **Button position** to the other option. If neither works, your theme has removed both
`displayProductAdditionalInfo` and `displayFooterProduct` from `product.tpl`; add
`{hook h='displayProductAdditionalInfo' product=$product}` where you want it.

**The button appears but nothing happens when I click it.**
A JavaScript error elsewhere on the page is halting the script. Open your browser console. Note
that unlike 3.x this module needs **no** jQuery and **no** Bootstrap — if you're debugging with
that assumption, drop it.

**No e-mail arrives, but reports appear in the list.**
This is the module working as designed — storage first, mail second. Check
**Advanced Parameters → E-mail** and send yourself a test. Then check the Status panel for a
recipient warning, and **Advanced Parameters → Logs** for `ReportBrokenLink: Mail::Send() failed`.

**Notifications land in spam.**
3.x sent them with the *customer's* address in `From:`, so they failed SPF and DKIM at the
receiving end. 4.0 sends from your shop address and puts the reporter in `Reply-To`. If they're
still spam-foldered, the problem is your shop's own SPF/DKIM records.

**A visitor says the form told them it expired.**
The signed token lasts 2 hours. Someone who left a product page open longer sees this. It's a
reload, not a bug. Tokens are also bound to the product, so they can't be replayed across your
catalogue.

**A visitor can't submit — "you have already reported this recently".**
The per-product rate limit (default: 1/hour). Raise it or set it to 0 in Spam protection.

**Reports appear as "Duplicate" and I'm not notified.**
Deduplication is on: same person, same product, same issue type, within 24h. They're all in the
list — filter by status *Duplicate*. Turn it off in Spam protection if you'd rather see every one.

**Excel mangles the CSV / shows a warning about formulas.**
The export prefixes cells starting with `=`, `+`, `-` or `@` with a tab, on purpose. A reporter
can type `=HYPERLINK(...)` into the message field and Excel would otherwise execute it when you
open the file.

**Turkish (or another language) strings show in English.**
`translations/en.php` and `translations/tr.php` ship complete. For another language, use
**International → Translations → Module translations**.

---

## Privacy / GDPR

- **The reporter's IP address is never stored.** Rate limiting needs to recognise a repeat
  visitor, so the module stores `hash_hmac('sha256', $ip, _COOKIE_KEY_)` instead. Keying it with
  your shop's own secret matters: an unkeyed SHA-256 of an IP is reversed in minutes by hashing
  all four billion IPv4 addresses.
- The name and e-mail address are **optional**, collected for one stated purpose (telling the
  reporter you fixed it), and shown to the reporter as such.
- If the official `psgdpr` module is installed and enabled, its consent block is rendered in the
  form automatically.
- Reports are deleted when their product is deleted, and all of them when the module is
  uninstalled.
- The reCAPTCHA option loads a Google script on your product pages. It is **off by default**;
  mention it in your privacy policy if you turn it on.

---

## Developer notes

### Hooks used

| Hook | Purpose |
|---|---|
| `displayProductAdditionalInfo` | Button (position: *info*) |
| `displayFooterProduct` | Button (position: *footer*) |
| `actionFrontControllerSetMedia` | Registers CSS/JS, product pages only |
| `actionProductDelete` | Deletes that product's reports |
| `displayDashboardTop` | Dashboard widget |

Zero overrides. Zero core file changes.

### Layout

```
reportbrokenlink.php                      module class, hooks, config page, CSV export
classes/ReportBrokenLinkValidator.php     sanitising + validation (no PrestaShop dependency)
classes/ReportBrokenLinkRepository.php    every SQL statement
controllers/front/report.php              submission endpoint
sql/install.php, sql/uninstall.php        schema
upgrade/upgrade-4.0.0.php                 3.x → 4.0.0
tests/ValidatorTest.php                   run: php tests/ValidatorTest.php
```

### Schema

`PREFIX_reportbrokenlink_report` — one row per report. `report_type` and `status` are `VARCHAR`,
not `ENUM`, so adding a value is a code change rather than an `ALTER` on a large table; allowed
values are enforced against `ReportBrokenLinkRepository::REPORT_TYPES` / `::STATUSES` on write.

There are **no `FOREIGN KEY` constraints**. PrestaShop creates tables with `_MYSQL_ENGINE_`,
which is MyISAM on a large share of shops, and MyISAM silently ignores foreign keys — declaring
them would be a no-op or an install failure depending on the host. Referential integrity is
enforced in code (`actionProductDelete`), and the admin list degrades gracefully to
*"Deleted product #123"* when a product has gone.

Indexes: `(id_product, status, created_at)`, `(id_shop, status, created_at)`,
`(ip_hash, id_product, created_at)` for the rate limiter, and `(created_at)`.

Timestamps are stored and displayed in the shop's timezone — PrestaShop applies `PS_TIMEZONE`
process-wide, so PHP's `date()` and MySQL's `NOW()` agree with each other and with the rest of
your back office.

### Assets

One CSS and one JS file for the front, one of each for the back — already bundled, no build
step. They are **not** pre-minified: PrestaShop's own **Advanced Parameters → Performance →
Smart cache / CCC** minifies and combines them along with everything else on the page. A
hand-minified copy checked into the repository would only drift from its source.

### Extending

`ReportBrokenLinkRepository` is the seam. Adding an issue type means adding it to
`REPORT_TYPES`, to `getTypeLabels()`, and to the installer's default `REPORTBROKENLINK_TYPES` —
no schema change.

### Testing

```
php tests/ValidatorTest.php      # 47 assertions, no shop required
```

Everything else needs a live shop; the checks worth running by hand are: submit a report as a
guest and as a customer, confirm it appears in the list *and* arrives by e-mail, submit twice to
trip the rate limit, resolve one with the notify box ticked, and export the CSV.

---

## Uninstall

**Uninstalling deletes every stored report**, along with all settings. Export to CSV first if
you might want them. You're asked to confirm.

---

## Changelog

See `CHANGELOG.md`. The security and correctness findings that motivated 4.0.0 are documented in
`AUDIT.md`.

---

2019-2026 MEG Venture — Academic Free License (AFL 3.0)
