<?php

return [
    'invoice_history_queue' => env('NETSUITE_INVOICE_HISTORY_QUEUE', 'invoices'),

    'scheduled' => (bool) env('NETSUITE_SYNC_SCHEDULED', false),
];
