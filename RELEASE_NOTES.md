# Report an Issue - Customer Product Page Feedback — Release notes

_Only customer-relevant changes are listed. Internal refactoring, tests and housekeeping are omitted._

## 4.1.2

### Fixed
- Security hardening: back-office actions. Settings, report updates, bulk actions, and CSV export now accept only HTTP POST. A GET with the same parameter names is ignored. List filters and confirmation flashes after save are unchanged
- Security hardening: reCAPTCHA verification. The siteverify call sends the secret key in the POST body instead of the URL query string

## 4.1.1

### Fixed
- The back-office icons turned into empty boxes on PrestaShop 9. The 17 icons the module draws for itself came from FontAwesome 4, which the back office shipped up to PrestaShop 8. PrestaShop 9 replaced it with Material Symbols Outlined, and FontAwesome now reaches the page only through `themes/default/public/theme.css` — a leftover of the old theme rather than anything the new back office asks for

## 4.1.0 — 2026-08-22

### New
- A single review-request line on the module's own configuration page. It appears at the earliest 21 days after installing, asks once for a short review on megventure.com, and disappears forever after a click, a "No thanks", or three unanswered views. It makes no outbound request of any kind and stores nothing beyond three prefixed configuration values, which uninstalling removes
- ---
