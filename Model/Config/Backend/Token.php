<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Math\Random;

class Token extends Value
{
    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\App\Config\ScopeConfigInterface $config,
        \Magento\Framework\App\Cache\TypeListInterface $cacheTypeList,
        private readonly Random $random,
        ?\Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        ?\Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    /** @throws LocalizedException */
    public function beforeSave(): self
    {
        $value = trim((string)$this->getValue());
        if ($value === '') {
            $value = bin2hex(random_bytes(20));
            $this->setValue($value);
        }
        return parent::beforeSave();
    }
}
