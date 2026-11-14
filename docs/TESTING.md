# v0.2.0 testing and release checklist

## Automated tests

Runtime module files use WHMCS's own Capsule. Composer dependencies are for development tests only; do not upload `vendor` into WHMCS.

```bash
composer install
composer test
python3 scripts/package.py
```

The standalone suite has 40 cases. Nine database/UI integration cases use Illuminate Capsule with an in-memory SQLite database by default. They test migrations, shared locking across invoice/test sources, duplicate preview/claim prevention, restart recovery, billing-table isolation and HTML escaping.

For MySQL, use a dedicated disposable database named exactly `onet_fiscal_test`. The suite resets only its fixed module and sentinel tables in that database. Never point it at a WHMCS database.

```bash
DB_DRIVER=mysql DB_DATABASE=onet_fiscal_test DB_HOST=127.0.0.1 \
DB_USERNAME=test_user DB_PASSWORD=your_local_test_password php tests/database.php
```

GitHub Actions runs syntax, unit, SQLite and MySQL 8 tests on PHP 8.3 and 8.4, and produces installation ZIP artifacts. No live fiscal credentials are used by CI. These checks do not certify regulatory compliance or a specific licensed WHMCS version.

## Upgrade smoke test on the user's WHMCS

1. Back up module files and module tables. Keep the existing private configuration, PFX and CA bundle.
2. Upload only `modules/addons/onetfiskal` from the installation ZIP.
3. Open the module. Confirm version 0.2.0, existing receipts and existing unresolved locks remain visible.
4. The additive migration creates `mod_onetfiskal_tests` and adds `source` to the shared guard. Old locks default to source `invoice`; no existing fiscal records are deleted.
5. Open **Zaseban sandbox test**. With the observed test VAT configuration of 19% and Ж, select `onetipsqr`, net 1000.00 and preview. Expect total 1190.00 and payment type 4.
6. Confirm that preview alone creates no PFR request. Click issue only after reviewing the sandbox confirmation.
7. Verify issued status, original receipt text and the official sandbox verification link.
8. Open that same test again. It must display the same receipt and must not offer another issue POST.
9. Create a new fictitious domain test with `corvuspay`, net 1000.00. Expect payment type 2 and total 1190.00.
10. Confirm WHMCS invoices, transactions, client records, tax settings and mail logs were not changed by these synthetic tests.

Select gateway identifiers already configured in `payment_map`. For a QR that only fills a bank payment order, use 4; integrated merchant instant payment has a separate code 6. Do not infer the method only from the word QR.

## Failure/recovery checks

- Keep certificate and PAK values out of logs, screenshots and Git.
- A failed status check sends no invoice POST.
- Unknown outcomes in either source block issuing in both sources. Follow the link in the lock notice to the correct record.
- Reconciliation first checks the saved original response, then uses a GET for the original RequestId if needed. A null lookup never automatically repeats POST.
- Do not clear the shared guard manually or change the certificate while the outcome is uncertain.
- Preview POST repeats return the same draft. Configuration changes require a refreshed preview before issue.

## Validation limits

Local development validation used PHP 8.4.27, 40 standalone tests and 9 SQLite integration/UI tests. GitHub CI supplies actual PHP 8.3/8.4 and MySQL checks. A full licensed-WHMCS smoke test and live sandbox issuance through the new isolated screen must be confirmed on the user's installation. No production fiscalization, advance/refund flow or customer email delivery is enabled.

## v0.3.0 phase 2

Follow [journal, QR, print and authorization acceptance checks](PHASE2-JOURNAL.md). Reuse existing issued receipts; do not create another fiscal transaction merely to test downloads.
