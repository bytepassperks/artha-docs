<?php

/**
 * Artha Business OS suite configuration.
 *
 * The Artha CRM is the suite identity provider: it mints a short-lived
 * HMAC-signed token for a signed-in user and hands off to this app's SSO
 * callback, which logs the matching user in. Off by default and a no-op until
 * ARTHA_SSO_SECRET is set, so deploying the code never changes login behaviour.
 */

return [
    'sso' => [
        'enabled' => env('ARTHA_SSO_ENABLED', false),
        'secret' => env('ARTHA_SSO_SECRET', ''),
    ],
];
