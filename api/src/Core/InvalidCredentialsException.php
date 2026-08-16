<?php

namespace App\Core;

/**
 * InvalidCredentialsException — authentication failed.
 *
 * Used for a failed login. The message deliberately stays generic
 * ("Invalid credentials.") whether the email was unknown or the
 * password was wrong, so the response never reveals which part failed.
 * Controllers map it to a 401 response.
 */
final class InvalidCredentialsException extends \RuntimeException
{
}