<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class ContentAttributeMode implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'all_except_excluded', 'label' => __('All exportable attributes except excluded ones')],
            ['value' => 'whitelist', 'label' => __('Whitelist only')],
        ];
    }
}
