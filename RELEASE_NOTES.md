# Report an Issue - Customer Product Page Feedback — Release notes

_Only customer-relevant changes are listed. Internal refactoring, tests and housekeeping are omitted._

## 4.1.3

### New
- The configuration page links to the free MEG Venture Updates module, which installs updates for this module in one click. The line is hidden once that module is installed

## 4.1.2

### Fixed
- Security hardening: back-office actions. Settings, report updates, bulk actions, and CSV export now accept only HTTP POST. A GET with the same parameter names is ignored. List filters and confirmation flashes after save are unchanged
- Security hardening: reCAPTCHA verification. The siteverify call sends the secret key in the POST body instead of the URL query string

## 4.1.1

### Fixed
- The back-office icons turned into empty boxes on PrestaShop 9. The 17 icons the module draws for itself came from FontAwesome 4, which the back office shipped up to PrestaShop 8. PrestaShop 9 replaced it with Material Symbols Outlined, and FontAwesome now reaches the page only through `themes/default/public/theme.css` — a leftover of the old theme rather than anything the new back office asks for
