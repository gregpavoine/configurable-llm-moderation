<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

$projectDir = dirname(__DIR__);
$jwtDirectory = $projectDir . '/var/jwt-test';
$privateKeyPath = $jwtDirectory . '/private.pem';
$publicKeyPath = $jwtDirectory . '/public.pem';
$passphrase = 'test-jwt-passphrase';

if (!is_file($privateKeyPath) || !is_file($publicKeyPath)) {
    if (!is_dir($jwtDirectory) && !mkdir($jwtDirectory, 0700, true) && !is_dir($jwtDirectory)) {
        throw new RuntimeException(sprintf('Unable to create JWT test key directory "%s".', $jwtDirectory));
    }

    $key = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    if (false === $key || !openssl_pkey_export($key, $privateKey, $passphrase)) {
        throw new RuntimeException('Unable to generate the JWT test private key.');
    }

    $details = openssl_pkey_get_details($key);
    if (false === $details || !isset($details['key'])) {
        throw new RuntimeException('Unable to generate the JWT test public key.');
    }

    if (false === file_put_contents($privateKeyPath, $privateKey)
        || false === file_put_contents($publicKeyPath, $details['key'])) {
        throw new RuntimeException('Unable to write the JWT test key pair.');
    }

    chmod($privateKeyPath, 0600);
    chmod($publicKeyPath, 0644);
}

foreach ([
    'JWT_SECRET_KEY' => $privateKeyPath,
    'JWT_PUBLIC_KEY' => $publicKeyPath,
    'JWT_PASSPHRASE' => $passphrase,
] as $name => $value) {
    $_SERVER[$name] = $value;
    $_ENV[$name] = $value;
}

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv($projectDir . '/.env');
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}
