<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Model;

use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory;

class AttributeSelector
{
    /**
     * Core/base attribute codes that are always exported everywhere (content stream and the
     * attributes dictionary), regardless of the include/exclude config.
     *
     * @var string[]
     */
    public const BASE_ATTRIBUTE_CODES = [
        'sku',
        'image',
        'created_at',
        'updated_at',
        'name',
        'description',
        'short_description',
        'url_key',
        'meta_title',
        'meta_description',
        'meta_keyword',
    ];

    private ?array $contentAttributes = null;

    public function __construct(
        private readonly Config $config,
        private readonly CollectionFactory $attributeCollectionFactory
    ) {
    }

    /**
     * @return string[]
     */
    public function getContentAttributeCodes(): array
    {
        if ($this->contentAttributes !== null) {
            return $this->contentAttributes;
        }

        $include = $this->config->getContentIncludeAttributes();
        if (!empty($include)) {
            // Explicit admin selection + always-on base codes.
            return $this->contentAttributes = $this->finalize($include);
        }

        // No explicit include list (e.g. a fresh store): default to filterable attributes.
        $exclude = array_flip(array_merge(
            $this->config->getContentExcludeAttributes(),
            $this->config->getPriceAttributeCodes(),
            ['status', 'quantity_and_stock_status', 'category_ids']
        ));

        $collection = $this->attributeCollectionFactory->create();
        $collection->addFieldToSelect(['attribute_code', 'frontend_input', 'is_filterable']);
        $collection->setOrder('attribute_code', 'ASC');

        $result = [];
        foreach ($collection as $attribute) {
            $code = (string)$attribute->getAttributeCode();
            if ($code === '' || isset($exclude[$code])) {
                continue;
            }
            if ((string)$attribute->getFrontendInput() === 'media_image') {
                continue;
            }
            if ((int)$attribute->getIsFilterable() <= 0) {
                continue;
            }
            $result[] = $code;
        }

        return $this->contentAttributes = $this->finalize($result);
    }

    /**
     * Union the selection with the always-on base codes, de-duplicate and sort.
     *
     * @param string[] $codes
     * @return string[]
     */
    private function finalize(array $codes): array
    {
        $codes = array_values(array_unique(array_merge($codes, self::BASE_ATTRIBUTE_CODES)));
        sort($codes);
        return $codes;
    }
}
