<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Expiry-tracked inventory warning buffer
    |--------------------------------------------------------------------------
    |
    | The product usage period and this buffer are subtracted from each lot's
    | expiry date to determine when it enters the Expiring Soon window.
    |
    */
    'expiry_warning_buffer_months' => 2,
];
