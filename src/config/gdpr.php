<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Anonymised-order retention
    |--------------------------------------------------------------------------
    |
    | An order erased under GDPR Art. 17 is anonymised, not deleted, because
    | it is an invoice and Bulgarian accounting law requires invoices to be
    | retained (explanation/gdpr.md, ADR-0019). Once that statutory period
    | expires the anonymised row should be deleted outright — nothing about
    | it is personal data any more, so the accounting basis is all that keeps
    | it, and it stops keeping it.
    |
    | The exact figure is a matter of Bulgarian accounting and tax law, not
    | something this application can decide. Set GDPR_ORDER_RETENTION_YEARS
    | from that law before go-live. The default of 11 is deliberately
    | conservative (longer than the likely 5-year minimum). Leave it null to
    | disable the purge entirely — `orders:purge-anonymised` then does
    | nothing and says so, rather than guessing.
    |
    */

    'order_retention_years' => env('GDPR_ORDER_RETENTION_YEARS', 11),

];
