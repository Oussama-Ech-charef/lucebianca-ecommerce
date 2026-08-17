<?php

namespace App\Repositories;

/**
 * AdminRefreshTokenRepository — admin counterpart of RefreshTokenRepository.
 *
 * Admins are a fully separate table from users (spec section 4), and
 * refresh_tokens.user_id is a FK to users(id) only. Admin refresh tokens
 * therefore live in their own table, share the exact same code path by
 * overriding the table / identity column, and keep the two stores separate.
 */
final class AdminRefreshTokenRepository extends RefreshTokenRepository
{
    protected string $table = 'admin_refresh_tokens';

    protected string $idColumn = 'admin_id';
}