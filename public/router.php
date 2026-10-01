<?php

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = is_string($path) ? realpath(__DIR__.$path) : false;
if ($file !== false && str_starts_with($file, __DIR__.'/assets/') && is_file($file)) {
    return false;
}
require __DIR__.'/index.php';
