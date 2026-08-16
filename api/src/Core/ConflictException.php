<?php

namespace App\Core;

/**
 * ConflictException — the request collides with existing state.
 *
 * Controllers map it to a 409 Conflict response (e.g. registering an
 * email that already exists).
 */
final class ConflictException extends \RuntimeException
{
}