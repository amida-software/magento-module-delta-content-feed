<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Controller\Feed;

class Snapshot extends AbstractFeed
{
    protected function export(string $streamCode, int $cursor): array
    {
        return $this->feedExporter->exportSnapshot($streamCode, $cursor);
    }
}
