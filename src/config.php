<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

function openai() {
    return OpenAI::client(getenv('OPENAI_API_KEY'));
}

date_default_timezone_set('Asia/Kolkata');
define('APP_ENV', getenv('APP_ENV') ?: 'dev');

define('DB_DSN',  getenv('DB_PGDSN')  ?: 'pgsql:host=localhost;port=5432;dbname=dummy');
define('DB_USER', getenv('DB_PGUSER') ?: 'dbuser');
define('DB_PASS', getenv('DB_PGPASS') ?: 'dbpass');

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn  = defined('DB_DSN')  ? DB_DSN  : (getenv('DB_DSN')  ?: 'pgsql:host=localhost;port=5432;dbname=hiranandani');
    $user = defined('DB_USER') ? DB_USER : (getenv('DB_USER') ?: 'dbuser');
    $pass = defined('DB_PASS') ? DB_PASS : (getenv('DB_PASS') ?: 'dbpass');

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $pdo;
}
