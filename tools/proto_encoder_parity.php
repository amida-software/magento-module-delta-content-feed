<?php
declare(strict_types=1);

/**
 * Static contract check: the hand-written protobuf encoder (Model/Feed/FeedEncoder.php) must stay
 * in sync with the schema (proto/amida_product_delta_feed_v1.proto). For each message/encoder pair
 * below, every proto field must be read by the encoder and every encoded $state[...] key must have
 * a proto field. This catches "new payload field silently missing from protobuf" drift.
 *
 * Plain PHP, no Magento bootstrap. Exit 0 on success, 1 on mismatch.
 */

$root = dirname(__DIR__);
$protoPath = $root . '/proto/amida_product_delta_feed_v1.proto';
$encoderPath = $root . '/Model/Feed/FeedEncoder.php';

foreach ([$protoPath, $encoderPath] as $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Missing file: $path\n");
        exit(1);
    }
}

$proto = (string)file_get_contents($protoPath);
$encoder = (string)file_get_contents($encoderPath);

/** @return string[] */
function protoFields(string $proto, string $message): array
{
    if (!preg_match('/message\s+' . preg_quote($message, '/') . '\s*\{(.*?)\n\}/s', $proto, $m)) {
        fwrite(STDERR, "proto message not found: $message\n");
        exit(1);
    }
    $fields = [];
    foreach (explode("\n", $m[1]) as $line) {
        if (preg_match('/^\s*reserved\b/', $line)) {
            continue;
        }
        if (preg_match('/^\s*(?:repeated\s+)?[\w.]+\s+(\w+)\s*=\s*\d+\s*;/', $line, $f)) {
            $fields[$f[1]] = true;
        }
    }

    return array_keys($fields);
}

/** @return string[] */
function encoderStateKeys(string $encoder, string $method): array
{
    if (!preg_match('/function\s+' . preg_quote($method, '/') . '\s*\([^)]*\)\s*:\s*string\s*\{(.*?)\n    \}/s', $encoder, $m)) {
        fwrite(STDERR, "encoder method not found: $method\n");
        exit(1);
    }
    preg_match_all('/\$state\[\'(\w+)\'\]/', $m[1], $k);

    return array_values(array_unique($k[1]));
}

$pairs = [
    ['ProductState', 'encodeProductState'],
    ['CuratedProductState', 'encodeCuratedProduct'],
];

$ok = true;
foreach ($pairs as [$message, $method]) {
    $protoF = protoFields($proto, $message);
    $encF = encoderStateKeys($encoder, $method);
    sort($protoF);
    sort($encF);
    $notEncoded = array_values(array_diff($protoF, $encF));
    $notInProto = array_values(array_diff($encF, $protoF));
    if ($notEncoded !== [] || $notInProto !== []) {
        $ok = false;
        fwrite(STDERR, "[$message <-> $method] parity mismatch\n");
        if ($notEncoded !== []) {
            fwrite(STDERR, '  proto fields not encoded: ' . implode(', ', $notEncoded) . "\n");
        }
        if ($notInProto !== []) {
            fwrite(STDERR, '  encoded keys not in proto: ' . implode(', ', $notInProto) . "\n");
        }
    } else {
        echo "[$message <-> $method] OK (" . count($protoF) . " fields)\n";
    }
}

if (!$ok) {
    exit(1);
}

echo "Proto/encoder parity OK\n";
