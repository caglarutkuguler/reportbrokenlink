# reportbrokenlink — manual test checklist

PrestaShop **1.7 / 8 / 9**. Module **4.1.2**.

## Security fixes in 4.1.2

### Admin mutations (RBL-1)

- [ ] GET `…&configure=reportbrokenlink&rbl_bulk_delete=1&rbl_ids[]=N&token=…` does **not** delete reports
- [ ] GET `…&configure=reportbrokenlink&submitReportbrokenlinkSettings=1&…` does **not** change settings
- [ ] GET `…&configure=reportbrokenlink&rbl_export=1&…` does **not** download CSV
- [ ] Bulk actions still work via POST buttons
- [ ] Settings save still works (POST)
- [ ] Per-row save + optional customer notify still works (POST)
- [ ] Export CSV still downloads via the POST button (same filters as list)
- [ ] Flash confirmations (`rbl_conf`) still show after redirect GET

### reCAPTCHA verify (RBL-2)

- [ ] With reCAPTCHA enabled and valid keys, FO submit still succeeds
- [ ] Low-score / invalid token still rejected
- [ ] (Ops) reCAPTCHA secret no longer appears in web-server access-log query strings for siteverify

## Regression

- [ ] Product-page report button + JSON submit still work (POST + signed token)
- [ ] Filter GET form + pagination still work
- [ ] Dashboard widget still links to configure page
