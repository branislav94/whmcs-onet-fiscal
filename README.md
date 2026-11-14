# WHMCS ONET Fiscal

WHMCS fiscalization module for Serbia's eFiskalizacija system, developed for UTG / ONET.

**Current version: 0.1.2 — sandbox development only.** This is not an accredited production ESIR. Production endpoints are blocked.

## Current functionality

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
```

34 standalone tests passed locally on PHP 8.4.27. Manual user validation confirmed a sandbox invoice could be issued and subsequently reconciled in WHMCS. This is not a full compatibility, regulatory or production certification. The tests use synthetic data and mocked fiscal responses; they require no fiscal credentials or live network access.

## Development

See [ROADMAP.md](ROADMAP.md) for the next phases. Keep stable checkpoints on `main` and use `feature/...` branches for further work.

Never commit private certificates, keys, PAK values, real passwords, filled configuration, database dumps or customer fiscal responses. `.gitignore` excludes common credential and runtime file types.
