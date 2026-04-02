<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Model\Config\Source;

use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory;
use Magento\Framework\Data\OptionSourceInterface;

class ProductAttributes implements OptionSourceInterface
{
    public function __construct(private readonly CollectionFactory $collectionFactory)
    {
    }

    public function toOptionArray(): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToSelect(['attribute_code', 'frontend_label']);
        $collection->setOrder('attribute_code', 'ASC');

        $options = [];
        foreach ($collection as $attribute) {
            $code = (string)$attribute->getAttributeCode();
            if ($code === '') {
                continue;
            }
            $label = trim((string)$attribute->getFrontendLabel());
            $options[] = [
                'value' => $code,
                'label' => $label !== '' ? sprintf('%s (%s)', $code, $label) : $code,
            ];
        }

        return $options;
    }
}
