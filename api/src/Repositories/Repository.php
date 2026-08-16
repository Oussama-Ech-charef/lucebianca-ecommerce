<?php

namespace App\Repositories;

use App\Core\Database;
use PDO;

/**
 * Repository — base class for all repositories.
 *
 * Every repository shares the single PDO connection from App\Core\Database
 * (no new connection per query). Subclasses write ONLY prepared statements
 * and bind values — never string-concatenated SQL (security requirement 3.1).
 */
abstract class Repository
{
    protected PDO $db;

    public function __construct()
    {
        $this->db = Database::get();
    }
}