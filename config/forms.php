<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Maximum submission size
    |--------------------------------------------------------------------------
    |
    | The maximum size of a submission payload in kilobytes. This protects
    | the application from large or malicious requests.
    |
    */

    'max_submission_size_kb' => env('FORMS_MAX_SUBMISSION_SIZE_KB', 256),

    /*
    |--------------------------------------------------------------------------
    | Submission rate limit
    |--------------------------------------------------------------------------
    |
    | The maximum number of submissions a single IP can make per form per hour.
    |
    */

    'submission_rate_limit' => env('FORMS_SUBMISSION_RATE_LIMIT', 60),

    /*
    |--------------------------------------------------------------------------
    | Allowed CORS origins
    |--------------------------------------------------------------------------
    |
    | A global list of allowed origins that supplements the per-form list.
    |
    */

    'global_allowed_origins' => array_filter(explode(',', (string) env('FORMS_GLOBAL_ALLOWED_ORIGINS', ''))),

    /*
    |--------------------------------------------------------------------------
    | Trusted proxies
    |--------------------------------------------------------------------------
    |
    | When the application runs behind one or more reverse proxies (Laravel
    | Cloud, nginx, Cloudflare, a load balancer, etc.) the real client IP,
    | scheme, and host are conveyed via X-Forwarded-* headers. To read those
    | headers, the application must declare which proxies it trusts. Use "*"
    | to trust the first hop, or a comma-separated list of IPs / CIDR ranges.
    | Leave empty to keep the framework default (do not trust proxies).
    |
    */

    'trusted_proxies' => env('TRUSTED_PROXIES') ?: null,
];
