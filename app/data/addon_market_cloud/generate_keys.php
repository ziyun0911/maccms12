<?php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

if (!function_exists('openssl_pkey_new')) {
    fwrite(STDERR, "OpenSSL extension required\n");
    exit(1);
}

$dir = __DIR__;
$keyDir = $dir . '/keys';
if (!is_dir($keyDir)) {
    mkdir($keyDir, 0755, true);
}

$privatePath = $keyDir . '/catalog_private.pem';
$publicPath = $dir . '/catalog_public.pem';

if (is_file($privatePath)) {
    fwrite(STDERR, "Private key already exists: {$privatePath}\nRefusing to overwrite.\n");
    exit(1);
}

$key = openssl_pkey_new([
    'private_key_bits' => 2048,
    'private_key_type' => OPENSSL_KEYTYPE_RSA,
]);
if ($key === false) {
    fwrite(STDERR, "Key generation failed\n");
    exit(1);
}

openssl_pkey_export($key, $privatePem);
$details = openssl_pkey_get_details($key);
$publicPem = $details['key'] ?? '';

file_put_contents($privatePath, $privatePem);
chmod($privatePath, 0600);
file_put_contents($publicPath, $publicPem);

echo "Private key: {$privatePath}\n";
echo "Public key:  {$publicPath}\n";
echo "Copy PEM into AddonCloudService::CATALOG_PUBLIC_KEY_PEM for built-in fallback.\n";
