<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Model\Compression;

use Magento\Framework\Exception\LocalizedException;
use Amida\ProductDeltaFeed\Api\CompressorInterface;

class ZstdCompressor implements CompressorInterface
{
    public function compress(string $payload, int $level = 3): string
    {
        if (function_exists('zstd_compress')) {
            $result = zstd_compress($payload, $level);
            if ($result === false) {
                throw new LocalizedException(__('ext-zstd failed to compress the payload.'));
            }
            return $result;
        }

        $binary = $this->findBinary();
        if ($binary === null) {
            throw new LocalizedException(__('Zstandard support is required. Install ext-zstd or a zstd binary on the Magento host.'));
        }

        $command = escapeshellcmd($binary) . ' -q --stdout -' . max(1, min(19, $level));
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes);
        if (!is_resource($process)) {
            throw new LocalizedException(__('Unable to start the zstd process.'));
        }

        fwrite($pipes[0], $payload);
        fclose($pipes[0]);
        $compressed = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            throw new LocalizedException(__('zstd failed: %1', trim((string)$stderr)));
        }

        return (string)$compressed;
    }

    private function findBinary(): ?string
    {
        foreach (['zstd', '/usr/bin/zstd', '/bin/zstd'] as $candidate) {
            if ($candidate === 'zstd') {
                $which = trim((string)shell_exec('command -v zstd 2>/dev/null'));
                if ($which !== '') {
                    return $which;
                }
                continue;
            }

            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
