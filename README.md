# ONET Fiskal — v0.9.0 sandbox

Administrator-only customer-delivery preview: resolve the original WHMCS client's email, preview sandbox HTML/text messages and exact saved HTML/TXT attachments. Internal advance closing documents and their copies are excluded. No mail transport, delivery queue writes, public client access or server-generated PDF is enabled. [Phase 6 preview and upgrade guide](docs/PHASE6-DELIVERY.md).


Task-first overview across all five document sources: unresolved/rejected outcomes first, then confirmation drafts, exact WHMCS identities, requested/confirmed amounts, and explicit continuation links. An incomplete internal advance settlement is surfaced until a final draft exists. Document previews separate a prominent amount summary from line items and highlight the final confirmation action. [Read-only design preview](docs/UI-PREVIEW.html). No change to fiscal handlers, schema or private configuration.


WHMCS invoice identities now pair the internal ID with the exact assigned `invoicenum`, without constructing a prefix or sequence. Batched read-only lookups cover recent invoices, details, journal and linked refunds; missing numbers leave the ID visible. Visual refinements follow BoardUI's typography, neutral surfaces, table composition and semantic states, implemented in scoped PHP/CSS without a new frontend dependency.


Scoped WHMCS addon redesign: readable Serbian currency summaries, numbered workflow steps, clear preview/issue/reconcile actions, and separate customer-return versus internal-advance-settlement paths. Existing financial handlers, locking, authorization and saved receipt journals are preserved. Upgrade the entire addon including `assets/admin.css` and `lib/Ui.php`; no new database schema or private-config changes. [UI guide and upgrade checklist](docs/UI-GUIDE.md).

## v0.6.0 foundation

Manual fictitious advance orders: Advance/Sale, customer returns, separate internal Advance/Refund settlement and a final Normal/Sale with only the new top-up payment. Durable group evidence and shared locking support recovery between settlement and final issuance. Includes advance annotations, protected exports, copies and ordinary final-sale refunds. No production, banking, WHMCS order mutation or email automation. [Upgrade and exact acceptance cases](docs/PHASE5-ADVANCES.md).

## v0.5.0 foundation

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
