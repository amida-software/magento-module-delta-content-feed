<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class SitemapMode implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'summary', 'label' => __('Summary')],
            ['value' => 'full', 'label' => __('Full (bounded by limit)')],
        ];
    }
}
