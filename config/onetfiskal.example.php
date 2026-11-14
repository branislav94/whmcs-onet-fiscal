<?php
declare(strict_types=1);

// COPY OUTSIDE public_html and the WHMCS directory before filling secrets.
// Use a dedicated sandbox invoicing certificate. Do not commit the filled file.
return [
    'environment' => 'sandbox',
    'vsdc_url' => 'https://vsdc.sandbox.suf.purs.gov.rs',
    'pfx_path' => '/home/CPANEL_USER/onet-private/sandbox-invoicing.pfx',
    'pfx_password' => 'UNESI_LOZINKU_PFX_FAJLA',
    'pac' => 'UNESI_PAK_ZA_ISTI_SERTIFIKAT',
    'expected_uid' => 'UNESI_JID',
    // Use a PEM CA bundle containing the official Sandbox RCA and Issuing CA.
    'ca_bundle' => '/home/CPANEL_USER/onet-private/sandbox-ca-bundle.pem',
    // Sandbox currently returns VAT 19% with Cyrillic Ж for this setup.
    // These are TEST settings, not Serbian production tax rules.
    'vat_rate' => 19,
    'tax_label' => 'Ж',
    // Keys are actual WHMCS transaction gateway identifiers, not display names.
    // Add only methods you really use. 4 = wire transfer, 2 = payment card.
    // Do not classify instant payment without checking the Serbian code mapping.
    'payment_map' => [
        'banktransfer' => 4,
        'corvuspay' => 2,
        'onetipsqr' => 4, // QR that fills a bank transfer order; not an integrated merchant IPS payment.
        // 'your_card_gateway' => 2,
    ],
];
