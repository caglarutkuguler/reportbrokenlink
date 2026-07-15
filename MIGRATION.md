# Migration guide — 3.x → 4.0.0

For anyone upgrading a live shop, and for anyone wondering why 4.0.0 looks the way it does.

---

## The short version

Install 4.0.0 over 3.x. Nothing to do by hand. Then open **Configure** and check the Status
panel is green — in particular that **Send new reports to** holds an address you read.

There is **no data migration**, because there is no data: 3.x stored nothing at all.

---

## 1. What the upgrade script does

`upgrade/upgrade-4.0.0.php` runs automatically on the version bump.

1. **Creates `PREFIX_reportbrokenlink_report`** and seeds the settings that never existed. The
   notification address defaults to `PS_SHOP_EMAIL`, which is where 3.x nominally sent reports —
   so the upgrade preserves the previous intent rather than pointing mail somewhere new.
2. **Unregisters `extraLeft`**, a 1.4–1.6 hook that 3.x registered on anything up to *and
   including* 1.7.0.0. It does nothing on a 1.7+ shop.
3. **Registers** `displayFooterProduct`, `actionFrontControllerSetMedia`, `actionProductDelete`
   and `displayDashboardTop`, and re-registers `displayProductAdditionalInfo` if it is somehow
   missing.
4. **Deletes the 3.x files from disk** (see §2).

`displayProductAdditionalInfo` is deliberately left registered. The button stays exactly where
merchants already expect it. The module name and `module_key` are unchanged, so this is an
in-place upgrade, not a reinstall.

## 2. Why the upgrade deletes files

PrestaShop extracts a module archive **over** the existing folder. It does not replace it.
Anything 4.0.0 renamed or dropped stays on disk until something removes it — and two of those
leftovers are not harmless:

| File | Why it must go |
|---|---|
| `controllers/front/ajax.php` | Still a **routable, unauthenticated front controller** that sends mail to a client-supplied recipient. It is an open relay for as long as the file exists, whether or not anything links to it. |
| `reportbrokenlink_ajax.php` | 3.0.0's endpoint. Bootstraps the shop itself via `config.inc.php` and does the same thing. |
| `views/templates/front/reportbrokenlink-extra.tpl` | Contains the CDN jQuery include and the old inline form. |
| `views/css/reportbrokenlink.css`, `views/img/*`, `logo.gif` | Dead weight. |

If you deploy by rsync or by hand rather than through the Module Manager, **delete those files
yourself.** Leaving `controllers/front/ajax.php` behind leaves the relay open.

## 3. What changed for your customers

**The seven checkboxes are gone.** 3.x offered a fixed list — "Main product image/images are not
displayed", "The download file is missing"… — and 4.0.0 replaces them with an issue-type
dropdown (Broken link / Wrong price / Missing information / Something else) plus a free-text
message.

The old list could only describe problems someone had thought of in advance, and it had
oddities: the "I purchased the virtual product but received no download" box was hidden for
physical products, while "the download file is missing" was shown on every one of them. A
sentence covers everything, including the case nobody anticipated.

Nothing is lost in the change, because 3.x never stored a report.

Also new for customers: the form validates properly (it could not fail before), tells them the
truth about whether it worked (it always claimed success before), and no longer loads a 2012
jQuery from Google's CDN on every product page.

## 4. What changed for you

The module gains a configure page — it had none — and a reports dashboard. Reports now live in
your shop. E-mail is a notification, not the storage: **if your mail server fails, you still
have the report.** That is the single most important change, given 3.1.0–3.2.2 lost every one.

## 5. How the overrides were replaced

**They weren't — there were none.** No version of this module, 3.0.0 through 3.2.2, ever shipped
an `/override` directory. The "zero overrides" goal was met before this work started.

The nearest thing was 3.0.0's `reportbrokenlink_ajax.php`, a raw PHP file that bootstrapped
PrestaShop by requiring `config/config.inc.php` and `init.php` directly. 3.1.0 had already
correctly replaced it with a `ModuleFrontController` — and, in doing so, introduced the mail path
bug that broke the module for four versions.

4.0.0 keeps the front-controller approach and fixes what 3.1.0 got wrong:

| 3.x | 4.0.0 |
|---|---|
| Work done in `__construct()`, before PrestaShop's `init()` — bypassing maintenance mode and SSL handling | `postProcess()` / `displayAjax()` with `$this->ajax = true`, after `init()` |
| `dirname(__FILE__) . '/mails/'` (resolved to a directory that does not exist) | `_PS_MODULE_DIR_ . $this->name . '/mails/'` |
| `new ReportBrokenLink()` on every request | `$this->module`, already resolved |
| `Content-Type` header commented out | `application/json`, set |
| `die('0')` on failure — valid JSON, parsed as success | A structured `{success: false, message}` payload |

## 6. Deviations from the brief, and why

Three requirements in the original specification were not implementable as written. Each is a
deliberate, documented decision.

### Symfony forms and Doctrine ORM → `HelperForm` + direct SQL

The brief asked for both "tested & working on PS 1.7.0" and "admin form uses SymfonyForm". These
are mutually exclusive: usable Symfony admin routing and Doctrine entity mapping for *modules*
land well after 1.7.0, and PS 9 has moved again since. One codebase cannot do both.

`HelperForm` + `Configuration` + direct SQL behaves identically from 1.7.0 through 9.x, and is
what every other module in this catalogue uses. The version matrix won.

### Twig front templates → Smarty `.tpl`

The brief's target structure listed `views/templates/front/button.html.twig`. The PrestaShop
**front office is Smarty on every version from 1.7 to 9** — Twig is back office only. A `.twig`
front template would not render at all.

### "Rate limit by IP" + "never store customer IP" → keyed hash

Both are satisfiable at once. The module stores `hash_hmac('sha256', $ip, _COOKIE_KEY_)` and
never the address. Keying it with the shop's own secret is what makes this real: an *unkeyed*
SHA-256 of an IP address is reversed in minutes by hashing all four billion IPv4 addresses, so
an unkeyed hash would still be personal data.

### Foreign keys → application-level integrity

The brief asked for `FOREIGN KEY` constraints to `product`, `shop` and `customer`. PrestaShop
creates module tables with `_MYSQL_ENGINE_`, which is **MyISAM on a large share of shops**, and
MyISAM silently ignores foreign keys. Declaring them would be a no-op or an install failure
depending on the host — which is why PrestaShop core does not use them either.

Integrity is enforced in code instead: `actionProductDelete` removes a deleted product's
reports, and the list renders *"Deleted product #123"* rather than breaking when one is missing.

## 7. Rolling back

Reinstalling 3.2.2 over 4.0.0 would leave the `reportbrokenlink_report` table in place
(harmless, and it preserves your reports if you later return to 4.x) and restore the open mail
relay and the broken mail path.

**Don't.** If 4.0.0 misbehaves, turn the button off with the master switch — reports stop, the
data stays, and the endpoint rejects submissions — and report the problem.

## 8. Before you release

The findings behind 4.0.0 come from source analysis, static checks and the unit suite
(`php tests/ValidatorTest.php`, 47 assertions). No PrestaShop installation was available while
this work was done, so the following are worth exercising on a real shop:

- [ ] Fresh install and uninstall on 1.7.0, 1.7.8, 8.x, 9.x
- [ ] **Upgrade** from 3.2.2 on at least one shop — confirm the table appears, the button stays
      put, and `controllers/front/ajax.php` is gone from disk afterwards
- [ ] Submit a report as a guest and as a signed-in customer; confirm it lands in the list **and**
      arrives by e-mail
- [ ] Submit twice in a row to trip the rate limit; submit the same issue twice to trip dedup
- [ ] Resolve a report with the notify box ticked; confirm the customer e-mail arrives
- [ ] Export the CSV and open it in Excel
- [ ] Try the button in both positions on your themes
- [ ] Confirm the old mail-path bug is really gone: submit one report, then check
      **Advanced Parameters → Logs** for `Mail::Send() failed`
