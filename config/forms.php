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
];
