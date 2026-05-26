<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Amida\ProductDeltaFeed\Model\Config;

class Streams implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => Config::STREAM_CONTENT, 'label' => __('Content')],
            ['value' => Config::STREAM_SEO, 'label' => __('SEO')],
            ['value' => Config::STREAM_PRICE, 'label' => __('Price')],
            ['value' => Config::STREAM_AVAILABILITY, 'label' => __('Availability')],
            ['value' => Config::STREAM_CATEGORY, 'label' => __('Category assignments')],
            ['value' => Config::STREAM_OFFER, 'label' => __('Offer')],
            ['value' => Config::STREAM_CATEGORIES, 'label' => __('Categories dictionary')],
            ['value' => Config::STREAM_CURATED, 'label' => __('Curated product')],
            ['value' => Config::STREAM_ALL, 'label' => __('All')],
        ];
    }
}
