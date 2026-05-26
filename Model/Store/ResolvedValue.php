<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Model\Store;

final class ResolvedValue
{
    public function __construct(
        public readonly mixed $value,
        public readonly string $source,
        public readonly string $provider,
        public readonly ?string $path = null,
        public readonly float $confidence = 1.0,
        public readonly ?string $note = null
    ) {
    }

    /** @return array<string, mixed> */
    public function toSourceMapEntry(): array
    {
        $entry = [
            'source' => $this->source,
            'provider' => $this->provider,
            'confidence' => $this->confidence,
        ];
        if ($this->path !== null && $this->path !== '') {
            $entry['path'] = $this->path;
        }
        if ($this->note !== null && $this->note !== '') {
            $entry['note'] = $this->note;
        }
        return $entry;
    }
}
