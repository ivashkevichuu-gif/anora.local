<?php

return [
    'api_key'                   => getenv('NOWPAYMENTS_API_KEY') ?: '',
    'ipn_secret'                => getenv('NOWPAYMENTS_IPN_SECRET') ?: '',
    'api_base_url'              => getenv('NOWPAYMENTS_API_BASE') ?: 'https://api.nowpayments.io/v1',
    'sandbox_mode'              => filter_var(getenv('NOWPAYMENTS_SANDBOX') ?: 'false', FILTER_VALIDATE_BOOLEAN),
    'manual_approval_threshold' => 500.00,
];
