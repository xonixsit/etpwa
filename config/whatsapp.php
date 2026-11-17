<?php

return [
    'api_base' => env('WA_API_BASE', 'https://graph.facebook.com/v20.0'),
    'token' => env('WA_ACCESS_TOKEN', ''),
    'phone_id' => env('WA_PHONE_ID', ''),
    'waba_id' => env('WA_WABA_ID', ''),
    'my_number' => env('WA_MY_NUMBER', ''),
    'app_secret' => env('WA_APP_SECRET', ''),
    'verify_token' => env('WA_VERIFY_TOKEN', ''),
    'export_token' => env('WA_EXPORT_TOKEN', ''),
    'template_choices' => array_values(array_filter(array_map('trim',
        explode(',', (string) env('WA_TEMPLATE_CHOICES', ''))))),
    'header_images' => [
        'etp_opener_brand' => env('WA_HEADER_IMAGE_ETP_OPENER_BRAND', ''),
    ],
    'ack_text' => env('WA_ACK_TEXT', ''),
    'opt_out_text' => env('WA_OPT_OUT_TEXT', ''),
    'window_hours' => 24,
    'event_retention_days' => 30,
];
