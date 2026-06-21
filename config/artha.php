<?php

/*
|--------------------------------------------------------------------------
| Artha Business OS — module configuration
|--------------------------------------------------------------------------
|
| Single sign-on consumer settings. The Artha CRM is the suite's identity
| provider: it mints a short-lived HMAC token for a signed-in user and hands
| off to this module's /artha/sso/callback, which logs the matching user in —
| no second password prompt. Off by default and a no-op until ARTHA_SSO_SECRET
| is set, so deploying never changes BookStack's existing login behaviour.
|
*/

return [
    'sso' => [
        'enabled' => env('ARTHA_SSO_ENABLED', false),
        'secret'  => env('ARTHA_SSO_SECRET', ''),
        'ttl'     => env('ARTHA_SSO_TTL', 120),

        // Where to land after a successful Artha SSO login.
        'home'    => env('ARTHA_SSO_HOME', '/'),

        // CRM launcher hand-off backing the "Continue with Artha" button.
        'crm_launch_url' => env('ARTHA_SSO_CRM_LAUNCH_URL', 'https://arthize.com/artha/launch/docs'),
    ],
];
