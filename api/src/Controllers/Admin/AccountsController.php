<?php

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\ConflictException;
use App\Core\Request;
use App\Core\RequestContext;
use App\Core\ValidationException;
use App\Repositories\AdminRepository;

/**
 * AccountsController — admin account management for the settings screen.
 *
 * Only the schema-supported fields are managed: name, email and password.
 * The role column is deliberately not editable — every admin is an equal
 * operator today, and there is no deactivation flag (removal is deletion).
 *
 * Deletion is guarded by AdminRepository::deleteGuarded(): an admin can
 * never delete their own account or the last remaining admin.
 */
final class AccountsController extends Controller
{
    private const MIN_PASSWORD_LENGTH = 8;
    private const MAX_PASSWORD_LENGTH = 200;

    private AdminRepository $admins;

    public function __construct()
    {
        $this->admins = new AdminRepository();
    }

    /**
     * GET /api/admin/accounts — list all admin accounts.
     */
    public function index(Request $request): never
    {
        $this->json([
            'data' => array_map(
                static fn ($admin): array => $admin->toArray(),
                $this->admins->all()
            ),
        ]);
    }

    /**
     * POST /api/admin/accounts — create an additional admin account.
     */
    public function store(Request $request): never
    {
        $body = $request->all();

        $name = is_string($body['name'] ?? null) ? trim($body['name']) : '';
        $email = $this->normalizeEmail($body['email'] ?? null);
        $password = is_string($body['password'] ?? null) ? $body['password'] : '';

        $errors = [];
        $this->validateName($name, $errors);
        $this->validateEmail($email, $errors);
        $this->validatePassword($password, $errors, required: true);

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        if ($this->admins->emailExists($email)) {
            $this->error('Email already exists.', 409, [
                'errors' => ['email' => 'An admin with this email already exists.'],
            ]);
        }

        $id = $this->admins->create(
            $name,
            $email,
            password_hash($password, PASSWORD_DEFAULT)
        );

        $admin = $this->admins->findById($id);
        $this->json(['data' => $admin?->toArray()], 201);
    }

    /**
     * PUT /api/admin/accounts/{id} — update name, email and optionally password.
     *
     * Omitting "password" (or sending an empty string) leaves it unchanged.
     * A password change revokes every session for that admin: refresh tokens
     * are marked revoked and access tokens lose their bound session id check.
     */
    public function update(Request $request, array $params): never
    {
        $id = (int) ($params['id'] ?? 0);
        $admin = $this->admins->findById($id);
        if ($admin === null) {
            $this->error('Admin account not found.', 404);
        }

        $body = $request->all();
        $errors = [];
        $fields = [];

        if (array_key_exists('name', $body)) {
            $name = is_string($body['name']) ? trim($body['name']) : '';
            $this->validateName($name, $errors);
            if (!isset($errors['name'])) {
                $fields['name'] = $name;
            }
        }

        if (array_key_exists('email', $body)) {
            $email = $this->normalizeEmail($body['email']);
            $this->validateEmail($email, $errors);
            if (!isset($errors['email'])) {
                if ($this->admins->emailExists($email, $id)) {
                    $this->error('Email already exists.', 409, [
                        'errors' => ['email' => 'An admin with this email already exists.'],
                    ]);
                }
                $fields['email'] = $email;
            }
        }

        if (array_key_exists('password', $body)) {
            $password = is_string($body['password']) ? $body['password'] : '';
            if ($password !== '') {
                $this->validatePassword($password, $errors, required: false);
                if (!isset($errors['password'])) {
                    $fields['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
                }
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        if ($fields === []) {
            throw new ValidationException(['_general' => 'No account fields provided.']);
        }

        $this->admins->update($id, $fields);
        $updated = $this->admins->findById($id);

        $this->json(['data' => $updated?->toArray()]);
    }

    /**
     * DELETE /api/admin/accounts/{id} — remove an admin account.
     *
     * Self-deletion and deleting the last admin are rejected with 409.
     * Existing access tokens stop working immediately because the session FK
     * cascades on delete and AuthMiddleware checks the live session row.
     */
    public function destroy(Request $request, array $params): never
    {
        $id = (int) ($params['id'] ?? 0);
        $claims = RequestContext::get('auth', []);
        $actorId = (int) ($claims['sub'] ?? 0);

        try {
            $this->admins->deleteGuarded($id, $actorId);
        } catch (ConflictException $e) {
            $this->error($e->getMessage(), 409);
        }

        $this->noContent();
    }

    /**
     * @param array<string, string> $errors
     */
    private function validateName(string $name, array &$errors): void
    {
        if ($name === '') {
            $errors['name'] = 'Name is required.';
        } elseif (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
            $errors['name'] = 'Name must be 2-100 characters.';
        }
    }

    /**
     * @param array<string, string> $errors
     */
    private function validateEmail(string $email, array &$errors): void
    {
        if ($email === '') {
            $errors['email'] = 'Email is required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) {
            $errors['email'] = 'Email must be a valid address (max 150 characters).';
        }
    }

    /**
     * @param array<string, string> $errors
     */
    private function validatePassword(string $password, array &$errors, bool $required): void
    {
        if ($password === '') {
            if ($required) {
                $errors['password'] = 'Password is required.';
            }
            return;
        }

        if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
            $errors['password'] = 'Password must be at least ' . self::MIN_PASSWORD_LENGTH . ' characters.';
        } elseif (strlen($password) > self::MAX_PASSWORD_LENGTH) {
            $errors['password'] = 'Password must be at most ' . self::MAX_PASSWORD_LENGTH . ' characters.';
        }
    }

    private function normalizeEmail(mixed $raw): string
    {
        return is_string($raw) ? strtolower(trim($raw)) : '';
    }
}
