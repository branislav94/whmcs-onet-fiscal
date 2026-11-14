# Development phases

## Current checkpoint — v0.2.0 candidate

- Manual WHMCS-to-V-PFR sandbox issuance confirmed.
- Unknown outcome caused by mixed-case identifiers reconciled from the saved response without reissuing.
- 40 standalone tests and 9 Capsule/SQLite integration/UI tests cover calculations, isolated test drafts, migration, source-aware locking and reconciliation.
- Isolated sandbox screen and actionable TLS/payment diagnostics implemented.
- PHP 8.3/8.4 and MySQL 8 GitHub Actions checks added.
- Remaining phase-1 acceptance: verify the new screen and upgrade on the user's licensed WHMCS, and review CI results.

## Phase 1 — Stable sandbox (target v0.2.0)

- Improve diagnostics for TLS, certificate, tax and payment mapping errors.
- Verify CorvusPay and ONET IPS QR flows using synthetic data and the actual recorded payment method.
- Add an isolated synthetic test-invoice screen so testing does not require altering global WHMCS tax settings.
- Test the exact supported WHMCS/PHP combinations and database concurrency behavior.
- Finish when new test invoices consistently reach confirmed issued status and failure cases recover safely.

## Phase 2 — Accounting rules

- Agree with the accountant when hosting, domain registration and renewals constitute supply.
- Specify advance versus final invoices, partial payments, account credit, refunds and customer identification.
- Define domestic B2C, B2B and foreign customer routing separately.
- Finish with reviewed examples and expected fiscal documents for each supported business event.

## Phase 3 — Complete fiscal document flows

- Implement required advance and final invoices with references, refunds and identification.
- Preserve an auditable chain between WHMCS transactions and fiscal documents.
- Finish when every agreed scenario passes sandbox validation, including interrupted requests.

## Phase 4 — Automation and customer delivery

- Introduce a durable processing queue and appropriate WHMCS event handling.
- Keep fiscal issuance status separate from email delivery status.
- Retry only delivery when email fails; do not generate another fiscal invoice.
- Display confirmed receipts in the client area and send electronic receipts to test recipients.
- Finish when the customer receives the correct confirmed receipt once, with safe delivery retries.

## Phase 5 — Accreditation preparation

- Check the current mandatory ESIR requirements and implement missing functions.
- Prepare user documentation and evidence for own-use approval.
- Complete the applicable technical and administrative review process.

## Phase 6 — Production rollout

- After approval, configure production certificates, tax labels and business premises.
- Validate supported production tax and payment mappings.
- Add monitoring, alerts, backup and operational recovery procedures.
- Start with a controlled, verified first production invoice and customer delivery.

These phases are plans. They do not imply current production readiness or approval.
