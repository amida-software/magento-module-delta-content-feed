<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Controller\Feed;

class Changes extends AbstractFeed
{
    protected function export(string $streamCode, int $cursor): array
    {
        return $this->feedExporter->exportChanges($streamCode, $cursor);
    }
}
