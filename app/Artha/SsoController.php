<?php

declare(strict_types=1);

namespace BookStack\Artha;

use BookStack\Access\LoginService;
use BookStack\Access\RegistrationService;
use BookStack\Users\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

/*
| Single sign-on consumer for the Artha Business OS. The Artha CRM (identity
| provider) mints a short-lived HMAC token for a signed-in user and hands off
| here. We verify the signature + expiry, then log the matching user in —
| auto-provisioning a BookStack user with the default role on first arrival.
| Additive + feature-flagged: with no shared secret this is a no-op, so the
| existing password / social login is never affected.
|
| Isolated in app/Artha so upstream BookStack updates merge without conflict.
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

        $claims = $this->verify((string) $request->query('artha_sso', ''), $secret);

        if ($claims === null) {
            return redirect('/login')->with('error', 'Your Artha sign-in link was invalid or has expired.');
        }

        try {
            $user = $this->resolveUser($claims['email'], $claims['name']);
            $this->loginService->login($user, 'artha-sso');
        } catch (Throwable) {
            return redirect('/login')->with('error', 'We could not sign you in from Artha. Please log in.');
        }

        return redirect('/');
    }

    protected function resolveUser(string $email, string $name): User
    {
        $existing = User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])->first();

        if ($existing instanceof User) {
            return $existing;
        }

        return $this->registrationService->registerUser([
            'name' => $name,
            'email' => $email,
            'password' => Str::random(32),
            'external_auth_id' => $email,
        ], null, true);
    }

    /**
     * @return array{email: string, name: string}|null
     */
    protected function verify(string $token, string $secret): ?array
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

    protected function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    protected function base64UrlDecode(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/'), true);
    }
}
