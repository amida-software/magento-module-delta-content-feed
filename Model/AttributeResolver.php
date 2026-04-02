<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Model;

use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory as ProductAttributeCollectionFactory;

class AttributeResolver
{
    /** @var array<string, string[]> */
    private array $cache = [];

    private const HARD_EXCLUDE = [
        'entity_id', 'attribute_set_id', 'type_id', 'created_at', 'updated_at',
        'row_id', 'required_options', 'has_options', 'media_gallery', 'options_container',
        'news_from_date', 'news_to_date', 'url_key', 'url_path', 'gift_message_available',
        'custom_design', 'custom_layout_update', 'page_layout', 'quantity_and_stock_status',
        'stock_data', 'category_ids', 'status', 'visibility'
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ProductAttributeCollectionFactory $attributeCollectionFactory
    ) {
    }

    /** @return string[] */
    public function resolveForStream(string $stream): array
    {
        if (isset($this->cache[$stream])) {
            return $this->cache[$stream];
        }

        return $this->cache[$stream] = match ($stream) {
            Config::STREAM_CONTENT => $this->resolveContentAttributes(),
            Config::STREAM_SEO => $this->config->getSeoAttributeCodes(),
            Config::STREAM_PRICE => $this->config->getPriceAttributeCodes(),
            default => [],
        };
    }

    /** @return string[] */
    private function resolveContentAttributes(): array
    {
        if ($this->config->getContentAttributeMode() === 'whitelist') {
            return $this->config->getContentAttributeCodes();
        }

        $exclude = array_flip(array_unique(array_merge(
            self::HARD_EXCLUDE,
            $this->config->getContentExcludedAttributeCodes(),
            $this->config->getSeoAttributeCodes(),
            $this->config->getPriceAttributeCodes(),
            ['qty', 'is_in_stock', 'manage_stock', 'backorders', 'min_qty', 'min_sale_qty', 'max_sale_qty']
        )));

        $collection = $this->attributeCollectionFactory->create();
        $collection->addVisibleFilter();
        $codes = [];
        foreach ($collection as $attribute) {
            $code = (string)$attribute->getAttributeCode();
            if ($code === '' || isset($exclude[$code])) {
                continue;
            }
            $codes[] = $code;
        }

        sort($codes);
        return array_values(array_unique($codes));
    }
}
