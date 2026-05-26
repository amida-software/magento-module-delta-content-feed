<?php
declare(strict_types=1);

require_once __DIR__ . '/../Model/Store/Normalizer/TextNormalizer.php';
require_once __DIR__ . '/../Model/Store/Normalizer/SpecialNormalizer.php';
require_once __DIR__ . '/../Model/Store/Normalizer/UrlNormalizer.php';

use Amida\ProductDeltaFeed\Model\Store\Normalizer\TextNormalizer;
use Amida\ProductDeltaFeed\Model\Store\Normalizer\SpecialNormalizer;
use Amida\ProductDeltaFeed\Model\Store\Normalizer\UrlNormalizer;

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
}

$text = new TextNormalizer();
assert_true($text->normalize('<script>x()</script><p>Hello&nbsp;<b>world</b></p>') === 'Hello world', 'HTML/script stripped');

$diag = [];
$special = new SpecialNormalizer();
assert_true($special->normalize(['devilery', 'loyality'], $diag) === ['delivery', 'loyalty'], 'special aliases normalized');
assert_true($diag === [], 'known aliases do not emit diagnostics');
$unknownDiag = [];
assert_true($special->normalize(['unknown-x'], $unknownDiag) === ['custom'], 'unknown special normalized to custom');
assert_true(($unknownDiag[0]['code'] ?? '') === 'page_special_unknown', 'unknown special diagnostic emitted');

$url = new UrlNormalizer();
$urlDiag = [];
assert_true($url->normalize('/delivery', 'https://example.com/ua/', $urlDiag) === 'https://example.com/ua/delivery', 'relative URL normalized');
$badDiag = [];
assert_true($url->normalize('javascript:alert(1)', 'https://example.com/', $badDiag) === null, 'unsafe URL rejected');
assert_true(($badDiag[0]['code'] ?? '') === 'unsupported_url_scheme', 'unsafe URL diagnostic emitted');

echo "Store metadata mock contract OK\n";
