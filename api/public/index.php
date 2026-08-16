<?php

/**
 * Front controller — single entry point for every API request.
 *
 * Apache rewrites all requests to this file (see .htaccess). For the PHP
 * built-in dev server, run:
 *     php -S 127.0.0.1:8000 public/index.php
 *
 * @see ../routes.php for the actual route definitions.
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../routes.php';