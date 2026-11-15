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

## v0.4.0 phase 3

Follow [refund acceptance checks](PHASE3-REFUNDS.md). Use fictitious IDs and a saved isolated sandbox sale. Test half then remaining half, original links, refund QR, unknown-outcome reconciliation and independent local money evidence. MySQL CI includes concurrent refund submissions.

## v0.5.0 phase 5 copies

Follow [copy acceptance checks](PHASE5-COPIES.md). Test a copy of an issued sale and an issued refund. Confirm new Copy documents on the sandbox portal while originals, refund balances and bank-return evidence remain unchanged.

## v0.6.0 phase 5 advances

Follow [advance, customer return and internal settlement acceptance](PHASE5-ADVANCES.md). Keep customer money returns distinct from internal AR. Verify AP → AR → PP, zero/new topup, restart between AR and PP and protected annotated output. MySQL CI includes a competing return-versus-settlement test.

## v0.7.0 UI workflow checks

`tests/ui-cases.php` runs within the database suite. It checks Serbian currency formatting and output escaping, continuation of an existing advance draft, separate customer-return/settlement actions, the final-document continuation after internal closing, reconciliation-only unknown refunds, and independent money evidence/export links for issued refunds. Existing issue/locking/refund/advance tests remain unchanged except display assertions for formatted totals. No live fiscal or bank calls. Visual acceptance in the installed WHMCS theme: [UI guide](UI-GUIDE.md).

## v0.7.1 invoice identities

Two additional database cases verify an exact single-query batch lookup of assigned WHMCS numbers, blank/deleted IDs, escaping of untrusted number strings, and the invoice list/detail/journal/refund integration. The original saved fiscal response and shared lock remain unchanged.
