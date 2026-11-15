# Development phases — revised sequence

## Phase 1 — Stable sandbox: verified
Manual issuance, transfer/card tests, saved response reconciliation and duplicate prevention verified on the user's WHMCS. PHP 8.3/8.4 and MySQL CI passed for v0.2.0.

## Phase 2 — Journal and receipt presentation: v0.3.1 verified
Read-only journal across both sources, filters and pagination; saved GIF QR; protected HTML/TXT downloads; browser print/Save as PDF; product/version and development installation identity. See [upgrade and acceptance checks](docs/PHASE2-JOURNAL.md). User confirmed the upgraded display; phase 2 PR #3 was merged.

## Phase 3 — Refunds: sandbox verified
Manual noncash full/partial refund flow and local test money-return evidence implemented. [First sandbox test and limitations](docs/PHASE3-REFUNDS.md). User verified full refund -4 of sale -3 (1190 RSD), partial refund -6 of sale -5 (2380 RSD, quantity 0.500), and reported the remaining-half flow working. PR #4 is merged.
Full/partial refunds, buyer identification and original-receipt references, safe duplicate prevention and sandbox examples. Fiscal correction and bank refund remain separate, linked operations.

## Phase 4 — Accounting rules: in parallel
Confirm supply timing for annual hosting, renewal and domains; advance/final documents; domestic business, individual and foreign customer routing, VAT and SEF. Existing accountant policy work remains provisional until reviewed.

## Phase 5 — Complete fiscal document flows: advances candidate v0.6.0
Copy/Sale -8 and Copy/Refund -9 verified by the user including official portal checks; PR #6 merged. Manual one-payment advance/return/internal-settlement/final flows implemented; [advance acceptance checks](docs/PHASE5-ADVANCES.md) await authenticated sandbox validation. This is one part of phase 5, not completion of the full checklist.
Implement agreed advances and closing flows, copies, applicable tax, quantities, item catalogue/GTIN and remaining mandatory ESIR functions. Validate the whole applicable checklist rather than assuming current tests establish compliance.

## Phase 6 — Automation and customer delivery
Durable processing queue, business-event rules, client access and email delivery with independent delivery retries. Do not reissue when email fails.

## Phase 7 — Approval
Complete technical self-assessment, documentation and evidence; submit for own-use technical and administrative review and address requested changes. Approval is not guaranteed by sandbox success.

## Phase 8 — Production rollout
After approval and business setup, production credentials/configuration, correct tax labels, monitoring and backup/recovery procedures; controlled first real receipt and delivery.

No production issuance is enabled. Phase 3 issues only explicitly confirmed sandbox refund documents.
