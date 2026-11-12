# Report an Issue - Customer Product Page Feedback — Release notes

_Only customer-relevant changes are listed. Internal refactoring, tests and housekeeping are omitted._

## 4.2.0

### New
- Multistore support. In the back office the report list, the status counters, the Dashboard widget and the CSV export now cover the shops of the current shop context (a single shop, a shop group or All shops), with a Shop column when multistore is active. Before, only the default shop's reports were visible in the All shops context. Status changes, saving a report, bulk actions and deleting only work on reports of those shops
- The "issue resolved" e-mail to a reporter now uses the name and sender settings of the shop the report was filed on, and the product link in the list points to that shop
- A notice on the configuration page explains the All shops / shop group context

### Fixed
- Opening the configuration page in the All shops or shop group context no longer stores the default shop's back-office link as the fallback for every shop; the link is stored only in a single shop context
- Multistore: a report filed on one shop no longer turns the same visitor's report of the same product on another shop into a quiet duplicate, which kept that shop's merchant from being alerted. The duplicate check looks at the shop the report is filed on; a single-shop install is unchanged

## 4.1.3

### New
- The configuration page links to the free MEG Venture Updates module, which installs updates for this module in one click. The line is hidden once that module is installed

## 4.1.2

### Fixed
- Security hardening: back-office actions. Settings, report updates, bulk actions, and CSV export now accept only HTTP POST. A GET with the same parameter names is ignored. List filters and confirmation flashes after save are unchanged
- Security hardening: reCAPTCHA verification. The siteverify call sends the secret key in the POST body instead of the URL query string
