<?php

$example = require __DIR__ . '/config.example.php';
$localPath = __DIR__ . '/config.local.php';

if (is_file($localPath)) {
    $local = require $localPath;
    return array_replace_recursive($example, $local);
}

return $example;

