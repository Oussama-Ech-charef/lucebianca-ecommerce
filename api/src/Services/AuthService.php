<?php

namespace App\Services;

use App\Core\Auth;
use App\Core\ConflictException;
use App\Core\Env;
use App\Core\InvalidCredentialsException;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Models\User;
use App\Repositories\RefreshTokenRepository;
use App\Repositories\UserRepository;

/**
 * AuthService — authentication business logic.
 *
 * Implements registration (password_hash, duplicate-email guard), login
 * (password_verify + JWT + refresh token issuance) and Google OAuth
 * (create-or-link by verified email). Password rule: MIN_PASSWORD_LENGTH = 8 —
 * a reasonable floor for this store's audience, enforced server-side here.
 *
 * Phase 16 (email verification): a verification link is emailed to new
 * password-registered customers via EmailService (Resend). Sending is
 * best-effort — a failed send never fails registration. Google accounts are
 * created already verified (Google proves the address) and never carry a
 * token. Verification is informational: login and checkout are not blocked
 * on it.
 */
final class AuthService
{
    private const MIN_PASSWORD_LENGTH = 8;

    /** Verification links stop working 24 h after issue. */
    private const VERIFICATION_TTL_SECONDS = 86400;

    private UserRepository $users;
    private RefreshTokenRepository $refreshTokens;
    private GoogleOAuthService $google;
    private EmailService $email;

    public function __construct()
    {
        $this->users         = new UserRepository();
        $this->refreshTokens = new RefreshTokenRepository();
        $this->google        = new GoogleOAuthService();
        $this->email         = new EmailService();
    }

    /**
     * Registers a new customer account and returns signed tokens.
     *
     * Validates every field server-side (spec 3.1), rejects a duplicate
     * email, hashes the password with password_hash(PASSWORD_DEFAULT),
     * then issues an access + refresh token pair.
     *
     * @throws ValidationException   When any input field fails validation.
     * @throws ConflictException     When the email is already registered.
     *
     * @return array{token: string, refresh_token: string, user: array} Auth payload
     *         to return verbatim to the client.
     */
    public function register(string $name, string $email, string $password, ?string $phone): array
    {
        $errors = Validator::validate(
            ['name' => $name, 'email' => $email, 'password' => $password],
            [
                'name'     => ['required'],
                'email'    => ['required', 'email'],
                'password' => ['required', ['min', self::MIN_PASSWORD_LENGTH]],
            ]
        );
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $email = strtolower(trim($email));
        $name  = trim($name);
        $phone = $phone === null ? null : trim($phone);

        if ($this->users->findByEmail($email) !== null) {
            // Simple message is fine for this store (not a high-security app).
            throw new ConflictException('Email already registered.');
        }

        $userId = $this->users->create(
            $name,
            $email,
            password_hash($password, PASSWORD_DEFAULT),
            $phone
        );

        // Phase 16: email the one-time verification link. Best-effort by
        // design — a failed send never fails registration (the account
        // exists, just unverified, and the user can resend from /account).
        try {
            $token  = $this->newVerificationToken();
            $this->users->setEmailVerification($userId, $token, $this->verificationExpiry());
            $user = $this->users->findById($userId);
            if ($user !== null) {
                $this->sendVerificationEmail($user);
            }
        } catch (\Throwable $e) {
            error_log('Luce Bianca: verification email not sent: ' . $e->getMessage());
        }

        return $this->issueTokenPair($userId);
    }

    /**
     * Authenticates a customer and returns signed tokens.
     *
     * Looks up by email; the same generic exception is thrown whether the
     * email is unknown or the password is wrong, so the response never
     * reveals which one failed.
     *
     * @throws ValidationException         When required inputs are missing/invalid.
     * @throws InvalidCredentialsException When the credentials do not match.
     *
     * @return array{token: string, refresh_token: string, user: array} Auth payload.
     */
    public function login(string $email, string $password): array
    {
        $errors = Validator::validate(
            ['email' => $email, 'password' => $password],
            [
                'email'    => ['required', 'email'],
                'password' => ['required'],
            ]
        );
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $user = $this->users->findByEmail(strtolower(trim($email)));

        if ($user === null || !password_verify($password, $user->passwordHash)) {
            throw new InvalidCredentialsException('Invalid credentials.');
        }

        return $this->issueTokenPair($user->id);
    }

    /**
     * Register/log in a user from a verified Google ID token.
     *
     * Create-or-link by email: Google has already verified the account, so
     * an existing user with the same email is linked (their google_id is set)
     * rather than rejected, and a brand-new email creates a user with an
     * unusable random password hash (password_hash stays NOT NULL; a Google
     * user has no password, so password_verify() always fails for them).
     *
     * @param string $idToken The JWT returned by Google Identity Services.
     *
     * @throws InvalidCredentialsException When the token fails verification.
     * @throws \RuntimeException           When Google cannot be reached.
     *
     * @return array{token: string, refresh_token: string, user: array} Auth payload.
     */
    public function loginWithGoogle(string $idToken): array
    {
        $claims = $this->google->verifyIdToken($idToken);

        $user = $this->users->findByGoogleId($claims['sub']);

        if ($user === null) {
            $user = $this->users->findByEmail($claims['email']);

            if ($user === null) {
                // No usable password for a Google-only account.
                $passwordHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
                $userId = $this->users->create(
                    $claims['name'] ?? $this->defaultNameFromEmail($claims['email']),
                    $claims['email'],
                    $passwordHash,
                    null,
                    $claims['sub'],
                    true
                );
                $user = $this->users->findById($userId);
            } elseif ($user->googleId === null) {
                // Existing email/password user signs in with Google — link it.
                $this->users->linkGoogle($user->id, $claims['sub']);
            }
        }

        /** @var User $user A row must exist after the create/lookup above. */
        return $this->issueTokenPair($user->id);
    }

    /**
     * Verifies a customer's email via their one-time link token.
     *
     * The token is single-use: marking the email verified also clears the
     * token and expiry, so the same link can never be replayed. Expired
     * links are rejected with a clear message directing the user to request
     * a new one (from /account or the resend endpoint).
     *
     * @throws ValidationException      When no token is supplied.
     * @throws InvalidCredentialsException When the token is unknown/already used
     *                                  or expired.
     */
    public function verifyEmail(string $token): void
    {
        $errors = Validator::validate(
            ['token' => $token],
            ['token' => ['required']]
        );
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $user = $this->users->findByEmailVerificationToken($token);

        if ($user === null) {
            throw new InvalidCredentialsException(
                'This verification link is invalid or has already been used.'
            );
        }

        $expiresAt = $user->emailVerificationExpiresAt;
        if ($expiresAt === null || strtotime($expiresAt) < time()) {
            throw new InvalidCredentialsException(
                'This verification link has expired. Request a new one from your account.'
            );
        }

        $this->users->markEmailVerified($user->id);
    }

    /**
     * Re-sends the verification email for an account.
     *
     * Anti-enumeration: the response is identical whether the email exists,
     * is already verified, or is unknown — only a real, unverified account
     * gets a fresh link (the old token is overwritten, so a stale link dies
     * immediately). The send itself is best-effort like registration.
     *
     * @throws ValidationException When the email is missing/invalid.
     */
    public function resendVerification(string $email): void
    {
        $errors = Validator::validate(
            ['email' => $email],
            ['email' => ['required', 'email']]
        );
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $user = $this->users->findByEmail(strtolower(trim($email)));

        if ($user === null || $user->emailVerified) {
            return;
        }

        $token = $this->newVerificationToken();
        $this->users->setEmailVerification($user->id, $token, $this->verificationExpiry());

        try {
            $user = $this->users->findById($user->id);
            if ($user !== null) {
                $this->sendVerificationEmail($user);
            }
        } catch (\Throwable $e) {
            error_log('Luce Bianca: verification email not sent: ' . $e->getMessage());
        }
    }

    /**
     * Exchanges a still-valid refresh token for a fresh token pair.
     *
     * Rotation: the presented token is revoked here and only the newly
     * issued pair can be used from now on, so a stolen token works at
     * most once. The raw token is never compared — only its hash.
     *
     * @throws ValidationException         When no refresh token is supplied.
     * @throws InvalidCredentialsException When the token is unknown, revoked
     *                                     or expired.
     *
     * @return array{token: string, refresh_token: string, user: array} New
     *         auth payload (same shape as login/register).
     */
    public function refresh(string $refreshToken): array
    {
        $errors = Validator::validate(
            ['refresh_token' => $refreshToken],
            ['refresh_token' => ['required']]
        );
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $hash = $this->hashToken($refreshToken);
        $row  = $this->refreshTokens->findActive($hash);

        if ($row === null) {
            throw new InvalidCredentialsException('Invalid or expired refresh token.');
        }

        // Rotate: the presented token is single-use — revoke it and issue
        // a fresh access + refresh pair.
        $this->refreshTokens->revoke($hash);

        try {
            return $this->issueTokenPair((int) $row['user_id']);
        } catch (\Throwable $e) {
            // If the new pair cannot be issued, restore the old token so the
            // client can retry instead of being locked out.
            $this->refreshTokens->create((int) $row['user_id'], $hash, $row['expires_at']);
            throw $e;
        }
    }

    /**
     * Logs a client out by revoking its refresh token.
     *
     * Idempotent: revoking an unknown/already-revoked token is a no-op, so
     * the client always gets a success response and repeat logouts are safe.
     *
     * @throws ValidationException When no refresh token is supplied.
     */
    public function logout(string $refreshToken): void
    {
        $errors = Validator::validate(
            ['refresh_token' => $refreshToken],
            ['refresh_token' => ['required']]
        );
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $this->refreshTokens->revoke($this->hashToken($refreshToken));
    }

    /**
     * Returns issuer time-to-live for logging/debugging.
     */
    public function tokenTtl(): int
    {
        return (int) Env::get('JWT_TTL_SECONDS', 3600);
    }

    /**
     * Issues an access token + a revocable refresh token, persists the
     * refresh token (hashed) and returns the client-facing auth payload.
     *
     * @param int $userId The authenticated/registered user's id.
     *
     * @return array{token: string, refresh_token: string, user: array}
     */
    private function issueTokenPair(int $userId): array
    {
        $accessTtl  = (int) Env::get('JWT_TTL_SECONDS', 3600);
        $refreshTtl = (int) Env::get('JWT_REFRESH_TTL_SECONDS', 604800);

        // Access token: short-lived JWT (stateless).
        $accessToken = Auth::issue($userId, 'user', $accessTtl);

        // Refresh token: 48 random bytes base64url-encoded (well above the
        // required 32). Only its SHA-256 hash is persisted — never the raw value.
        $refreshToken = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $expiresAt    = date('Y-m-d H:i:s', time() + $refreshTtl);

        $this->refreshTokens->create($userId, $this->hashToken($refreshToken), $expiresAt);

        /** @var User $user The user was just created/verified, so a row must exist. */
        $user = $this->users->findById($userId);

        return [
            'token'         => $accessToken,
            'refresh_token' => $refreshToken,
            'user'          => $user->toArray(),
        ];
    }

    /**
     * Deterministic SHA-256 hex hash of a refresh token — the only form the
     * raw token is ever persisted in.
     */
    private function hashToken(string $refreshToken): string
    {
        return hash('sha256', $refreshToken);
    }

    /**
     * A fresh, unguessable verification token (64 hex chars = 32 random bytes).
     */
    private function newVerificationToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * UTC datetime when a newly issued verification token stops being valid.
     */
    private function verificationExpiry(): string
    {
        return date('Y-m-d H:i:s', time() + self::VERIFICATION_TTL_SECONDS);
    }

    /**
     * The storefront origin the verification link must point at — the same
     * value the frontend uses as NEXT_PUBLIC_SITE_URL (see api/.env SITE_URL).
     */
    private function verificationBaseUrl(): string
    {
        return rtrim((string) Env::get('SITE_URL', 'http://localhost:3000'), '/');
    }

    /**
     * Emails the user's pending verification link. The token must already be
     * stored on the row (setEmailVerification) before this is called.
     */
    private function sendVerificationEmail(User $user): void
    {
        $link = $this->verificationBaseUrl()
            . '/verify-email?token='
            . rawurlencode((string) $user->emailVerificationToken);

        $this->email->sendVerificationEmail($user->email, $user->name, $link);
    }

    /**
     * Fallback display name from the email's local part when Google does not
     * provide one ("jane.doe@gmail.com" -> "jane.doe").
     */
    private function defaultNameFromEmail(string $email): string
    {
        $local = explode('@', $email)[0] ?? $email;

        return $local === '' ? 'Customer' : $local;
    }
}