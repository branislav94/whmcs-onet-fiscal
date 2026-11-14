# Development phases — revised sequence

## Phase 1 — Stable sandbox: verified
Manual issuance, transfer/card tests, saved response reconciliation and duplicate prevention verified on the user's WHMCS. PHP 8.3/8.4 and MySQL CI passed for v0.2.0.

## Phase 2 — Journal and receipt presentation: v0.3.0 candidate
Read-only journal across both sources, filters and pagination; saved GIF QR; protected HTML/TXT downloads; browser print/Save as PDF; product/version and development installation identity. See [upgrade and acceptance checks](docs/PHASE2-JOURNAL.md). Exact WHMCS/theme and real saved QR acceptance remains a user-environment check.

## Phase 3 — Refunds
Full/partial refunds, buyer identification and original-receipt references, safe duplicate prevention and sandbox examples. Fiscal correction and bank refund remain separate, linked operations.

## Phase 4 — Accounting rules: in parallel
Confirm supply timing for annual hosting, renewal and domains; advance/final documents; domestic business, individual and foreign customer routing, VAT and SEF. Existing accountant policy work remains provisional until reviewed.

## Phase 5 — Complete fiscal document flows
Implement agreed advances and closing flows, copies, applicable tax, quantities, item catalogue/GTIN and remaining mandatory ESIR functions. Validate the whole applicable checklist rather than assuming current tests establish compliance.

## Phase 6 — Automation and customer delivery
Durable processing queue, business-event rules, client access and email delivery with independent delivery retries. Do not reissue when email fails.

## Phase 7 — Approval
Complete technical self-assessment, documentation and evidence; submit for own-use technical and administrative review and address requested changes. Approval is not guaranteed by sandbox success.

## Phase 8 — Production rollout
After approval and business setup, production credentials/configuration, correct tax labels, monitoring and backup/recovery procedures; controlled first real receipt and delivery.

No production issuance is enabled. Phase 2 changes presentation and read-only access, not tax policy or fiscal issuance.
