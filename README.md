# ONET Fiskal — v0.5.0 sandbox

Adds explicitly confirmed Copy/Sale and Copy/Refund documents with original references, shared fiscal locking, reconciliation, a journal source and protected exports. Saved-original reprints remain read-only. Copies never change the refund balance, money-return evidence or WHMCS financial records. [Upgrade and first tests](docs/PHASE5-COPIES.md).

## v0.4.0 foundation

Manual full/partial noncash refunds of saved issued sales, preserving original prices, tax labels and references. Preview, buyer identification, shared fiscal locking, per-item quantity/cent limits, reconciliation and independent local test payment-return evidence. No bank API or WHMCS financial mutation. [Upgrade and first refund test](docs/PHASE3-REFUNDS.md).

## v0.3.0 sandbox foundation

Phase 2 adds a searchable, paginated journal across WHMCS invoices and isolated tests; saved V-PFR GIF QR rendering; admin-only standalone HTML/TXT downloads and browser printing / Save as PDF. No new issuance or email automation is enabled.

Upgrade the complete `modules/addons/onetfiskal` directory, including `receipt.php` and the new libraries. Keep private credentials and existing database evidence. Do not deactivate the addon. See [Phase 2 upgrade and acceptance checks](docs/PHASE2-JOURNAL.md) and [Serbian documentation](README-SR.md).

# WHMCS ONET Fiscal

WHMCS fiscalization module for Serbia's eFiskalizacija system, developed for UTG / ONET.

**Current version: 0.2.0 — sandbox development only.** This is not an accredited production ESIR. Production endpoints are blocked.

## Current functionality

- Issue a fictitious hosting/domain sandbox receipt from an isolated screen without touching WHMCS invoices, payments or global tax settings.
- Manually issue a sandbox Normal/Sale invoice from an eligible paid WHMCS invoice.
- Queue paid invoices for review without automatically fiscalizing them.
- Connect directly to the Serbian V-PFR sandbox using a private PFX certificate and PAK.
- Validate the configured sandbox VAT rate and label against the live status response.
- Persist requests and responses, prevent duplicate submissions, and reconcile uncertain outcomes.
- Recover a previously saved successful response without issuing another invoice.
- Display the original fiscal receipt text and official sandbox verification link in the admin area.

Scope: RSD, domestic individual customers, one full payment, exclusive tax pricing, configurable sandbox VAT 19% or 20%. The active sandbox rate must match the test invoice. Sandbox rates are not production tax rules.

Automatic fiscalization, advance invoices, refunds, customer email delivery and client-area receipts are planned, not implemented.

## Installation and configuration

See [README-SR.md](README-SR.md) for the Serbian installation guide, certificate setup, configuration, test scenarios and recovery instructions.

Only `modules/addons/onetfiskal` is uploaded into the WHMCS installation. Keep the filled configuration, PFX, PAK and CA bundle outside all public web directories. The repository contains only an example configuration.

## Requirements and validation

- PHP 8.3+ with cURL, OpenSSL and mbstring; libcurl 7.71+.
- WHMCS addon/hook API and Capsule database layer; target WHMCS 9.x.
- Dedicated sandbox credentials and outbound HTTPS to the official V-PFR sandbox.

Run the standalone tests:

```bash
php tests/run.php

# With development dependencies installed:
composer install
composer test
python3 scripts/package.py
```

40 standalone and 9 SQLite integration/UI tests passed locally on PHP 8.4.27. GitHub Actions checks PHP 8.3/8.4 and MySQL 8. See [docs/TESTING.md](docs/TESTING.md). Manual user validation confirmed a sandbox invoice could be issued and subsequently reconciled in WHMCS. This is not a full compatibility, regulatory or production certification. The tests use synthetic data and mocked fiscal responses; they require no fiscal credentials or live network access.

## Development

See [ROADMAP.md](ROADMAP.md) for the next phases. Keep stable checkpoints on `main` and use `feature/...` branches for further work.

Never commit private certificates, keys, PAK values, real passwords, filled configuration, database dumps or customer fiscal responses. `.gitignore` excludes common credential and runtime file types.
