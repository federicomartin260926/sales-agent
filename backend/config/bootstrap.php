<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (!class_exists(Dotenv::class)) {
    return;
}

$envFile = dirname(__DIR__).'/.env';

if (is_file($envFile)) {
    (new Dotenv())->bootEnv($envFile);
}
