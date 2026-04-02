<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Model;

use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory;

class AttributeSelector
{
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
            sort($include);
            return $this->contentAttributes = array_values(array_unique($include));
        }

        $exclude = array_flip(array_merge(
            $this->config->getContentExcludeAttributes(),
            $this->config->getSeoAttributeCodes(),
            $this->config->getPriceAttributeCodes(),
            ['status', 'quantity_and_stock_status', 'category_ids']
        ));

        $collection = $this->attributeCollectionFactory->create();
        $collection->addFieldToSelect(['attribute_code', 'frontend_input']);
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
            $result[] = $code;
        }

        sort($result);
        return $this->contentAttributes = array_values(array_unique($result));
    }
}
