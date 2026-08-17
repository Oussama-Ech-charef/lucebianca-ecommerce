<?php

declare(strict_types=1);

/**
 * create-admin.php — OFFline bootstrap for the very first admin account.
 *
 * There is deliberately NO public HTTP route that can create an admin
 * (spec section 4: admins are a separate table and no public admin
 * registration exists). This CLI script is the only way to seed one:
 *
 *   php scripts/create-admin.php                          # interactive
 *   php scripts/create-admin.php "Name" admin@x.dev pass  # non-interactive
 *
 * The password is hashed with password_hash() (bcrypt) and never printed.
 * Exit codes: 0 = created, 1 = validation/error.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, 'CLI-only script — refusing to run over HTTP.' . PHP_EOL);
    exit(1);
}

require __DIR__ . '/../src/autoload.php';

use App\Core\Env;
use App\Core\Validator;
use App\Repositories\AdminRepository;

Env::load(__DIR__ . '/../.env');

$name     = null;
$email    = null;
$password = null;

// argv[1..3] → non-interactive run; otherwise prompt on the command line.
$args = array_slice($argv, 1);
if (count($args) === 3) {
    [$name, $email, $password] = $args;
} else {
    fwrite(STDOUT, 'Admin name: ');
    $name = trim((string) fgets(STDIN));

    fwrite(STDOUT, 'Admin email: ');
    $email = trim((string) fgets(STDIN));

    fwrite(STDOUT, 'Admin password (min 8 chars): ');
    $password = trim((string) fgets(STDIN));
}

$errors = Validator::validate(
    ['name' => $name, 'email' => $email, 'password' => $password],
    [
        'name'     => ['required'],
        'email'    => ['required', 'email'],
        'password' => ['required', ['min', 8]],
    ]
);
if ($errors !== []) {
    foreach ($errors as $field => $message) {
        fwrite(STDERR, "  - {$message}" . PHP_EOL);
    }
    exit(1);
}

$email = strtolower(trim($email));
$name  = trim($name);

$admins = new AdminRepository();
if ($admins->findByEmail($email) !== null) {
    fwrite(STDERR, "Admin with email '{$email}' already exists." . PHP_EOL);
    exit(1);
}

$id = $admins->create($name, $email, password_hash($password, PASSWORD_DEFAULT));

fwrite(STDOUT, "Admin created OK: id={$id} name={$name} email={$email} role=admin" . PHP_EOL);
exit(0);