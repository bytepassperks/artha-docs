<?php

declare(strict_types=1);

namespace BookStack\Artha;

use BookStack\Access\LoginService;
use BookStack\Access\RegistrationService;
use BookStack\Exceptions\UserRegistrationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/*
| Single sign-on consumer for the Artha Business OS. The Artha CRM (identity
| provider) mints a short-lived HMAC token for a signed-in user and hands off
| here. We verify the signature + expiry, then log the matching user in via
| BookStack's own external-auth provisioning (findOrRegister + LoginService) —
| the exact path the native OIDC integration uses, so default roles, activity
| logging and login gates all behave identically. No second password prompt.
|
| Additive + feature-flagged: with no shared secret this is a no-op, so the
| existing password / OIDC / SAML login is never affected. Isolated in
| app/Artha so upstream BookStack updates merge without conflict.
*/
class SsoController
{
    public function __construct(
        protected RegistrationService $registrationService,
        protected LoginService $loginService,
    ) {
    }

    public function callback(Request $request): RedirectResponse
    {
        $secret = (string) config('artha.sso.secret', '');

        if (! (bool) config('artha.sso.enabled', false) || $secret === '') {
            return redirect('/login')->with('error', 'Artha single sign-on is not enabled.');
        }

        if (auth()->check()) {
            return redirect(config('artha.sso.home', '/'));
        }

        $claims = $this->verify((string) $request->query('artha_sso', ''), $secret);

        if ($claims === null) {
            return redirect('/login')->with('error', 'Your Artha sign-in link was invalid or has expired. Please log in.');
        }

        try {
            $user = $this->registrationService->findOrRegister(
                $claims['name'],
                $claims['email'],
                'artha:' . mb_strtolower($claims['email']),
            );
        } catch (UserRegistrationException) {
            return redirect('/login')->with('error', 'We could not sign you in from Artha. Please log in.');
        }

        $this->loginService->login($user, 'artha');

        return redirect(config('artha.sso.home', '/'));
    }

    /**
     * Verify the CRM-minted HMAC token and return its trusted claims.
     *
     * @return array{email: string, name: string}|null
     */
    private function verify(string $token, string $secret): ?array
    {
        if (substr_count($token, '.') !== 1) {
            return null;
        }

        [$encodedPayload, $encodedSignature] = explode('.', $token, 2);

        $expected = $this->base64UrlEncode(hash_hmac('sha256', $encodedPayload, $secret, true));

        if (! hash_equals($expected, $encodedSignature)) {
            return null;
        }

        $decoded = json_decode($this->base64UrlDecode($encodedPayload), true);

        if (! is_array($decoded)) {
            return null;
        }

        $email = isset($decoded['email']) && is_string($decoded['email']) ? trim($decoded['email']) : '';
        $exp = isset($decoded['exp']) && is_numeric($decoded['exp']) ? (int) $decoded['exp'] : 0;
        $issuer = isset($decoded['iss']) && is_string($decoded['iss']) ? $decoded['iss'] : '';

        if ($email === '' || $issuer !== 'crm' || $exp < time()) {
            return null;
        }

        $name = isset($decoded['name']) && is_string($decoded['name']) && trim($decoded['name']) !== ''
            ? trim($decoded['name'])
            : Str::before($email, '@');

        return ['email' => $email, 'name' => $name];
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/'), true);
    }
}
