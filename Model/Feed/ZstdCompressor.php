<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Model\Feed;

use Amida\ProductDeltaFeed\Model\Config;

class ZstdCompressor
{
    public function __construct(private readonly Config $config)
    {
    }

    public function isEnabled(): bool
    {
        return $this->config->isZstdEnabled();
    }

    public function isAvailable(): bool
    {
        return function_exists('zstd_compress');
    }

    public function compress(string $payload): string
    {
        if (!$this->isEnabled()) {
            return $payload;
        }

        if (!$this->isAvailable()) {
            throw new \RuntimeException('ext-zstd is not installed');
        }

        $compressed = zstd_compress($payload, $this->config->getZstdLevel());
        if (!is_string($compressed)) {
            throw new \RuntimeException('zstd_compress returned a non-string payload');
        }

        return $compressed;
    }
}
