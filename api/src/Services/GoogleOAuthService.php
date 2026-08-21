<?php

namespace App\Services;

use App\Core\Env;
use App\Core\InvalidCredentialsException;

/**
 * GoogleOAuthService — server-side verification of a Google Sign-In ID token.
 *
 * The frontend "Sign in with Google" button (Google Identity Services) returns
 * an id_token JWT. This service asks Google to verify it (tokeninfo endpoint)
 * and then hard-checks the claims that matter locally: the audience must match
 * our GOOGLE_CLIENT_ID, the issuer must be Google's, the token must not be
 * expired, and a verified email must be present. No external PHP library is
 * needed — verification is delegated to Google's endpoint, not hand-rolled.
 *
 * GOOGLE_CLIENT_ID lives in .env (gitignored). It is safe to ship the same id
 * to the browser via NEXT_PUBLIC_GOOGLE_CLIENT_ID — it is a public identifier;
 * the API additionally checks `aud` against it so tokens issued to another
 * Google client are rejected.
 */
final class GoogleOAuthService
{
    private const TOKENINFO_URL = 'https://oauth2.googleapis.com/tokeninfo';

    /**
     * Verifies a Google id_token and returns its validated claims.
     *
     * @param string $idToken The JWT returned by Google Identity Services.
     *
     * @return array{sub: string, email: string, name: string|null, email_verified: bool}
     *
     * @throws InvalidCredentialsException When the token is missing, invalid,
     *                                     expired, issued for another client,
     *                                     or carries no verified email.
     * @throws \RuntimeException           When Google cannot be reached.
     */
    public function verifyIdToken(string $idToken): array
    {
        if ($idToken === '') {
            throw new InvalidCredentialsException('Missing Google ID token.');
        }

        $claims = $this->fetchClaims($idToken);

        if (($claims['aud'] ?? '') !== $this->expectedAudience()) {
            throw new InvalidCredentialsException('Google ID token was issued for a different client.');
        }

        $issuer = (string) ($claims['iss'] ?? '');
        if ($issuer !== 'accounts.google.com' && $issuer !== 'https://accounts.google.com') {
            throw new InvalidCredentialsException('Invalid Google ID token issuer.');
        }

        if (isset($claims['exp']) && (int) $claims['exp'] < time()) {
            throw new InvalidCredentialsException('Google ID token has expired.');
        }

        $sub   = (string) ($claims['sub'] ?? '');
        $email = strtolower(trim((string) ($claims['email'] ?? '')));

        if ($sub === '' || $email === '') {
            throw new InvalidCredentialsException('Google ID token carries no account identifier.');
        }

        // email_verified is returned by Google Identity Services; treat a
        // value of 1/"true" as verified. Tokeninfo may omit the field for
        // accounts Google has not verified, which we refuse to trust.
        $verified = $claims['email_verified'] ?? false;
        $isVerified = $verified === 'true' || $verified === true || $verified === 1 || $verified === '1';

        if (!$isVerified) {
            throw new InvalidCredentialsException('Google account email is not verified.');
        }

        $name = isset($claims['name']) && is_string($claims['name']) && trim($claims['name']) !== ''
            ? trim((string) $claims['name'])
            : null;

        return [
            'sub'           => $sub,
            'email'         => $email,
            'name'          => $name,
            'email_verified' => true,
        ];
    }

    /**
     * The Google client id this API accepts tokens for.
     */
    private function expectedAudience(): string
    {
        return (string) Env::get('GOOGLE_CLIENT_ID', '');
    }

    /**
     * Asks Google's tokeninfo endpoint to validate the token and returns its
     * decoded claims.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidCredentialsException When Google rejects the token.
     * @throws \RuntimeException           When the request fails (network).
     */
    private function fetchClaims(string $idToken): array
    {
        $url = self::TOKENINFO_URL . '?id_token=' . rawurlencode($idToken);

        $context = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'timeout'       => 10,
                'ignore_errors' => true,
                'header'        => 'Accept: application/json',
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $line, $m) === 1) {
                $status = (int) $m[1];
            }
        }

        if ($body === false || $status >= 500) {
            throw new \RuntimeException('Google identity service is unreachable.');
        }

        if ($status >= 400) {
            throw new InvalidCredentialsException('Google rejected the ID token.');
        }

        $claims = json_decode($body, true);
        if (!is_array($claims)) {
            throw new InvalidCredentialsException('Google returned an unreadable response.');
        }

        return $claims;
    }
}