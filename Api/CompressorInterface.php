<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Api;

interface CompressorInterface
{
    public function compress(string $payload, int $level = 3): string;
}
