<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Model\Config\Backend;

use Magento\Framework\App\Config\Value;

class GeneratePublicKey extends Value
{
    public function beforeSave(): self
    {
        $value = trim((string)$this->getValue());
        if ($value === '') {
            $value = bin2hex(random_bytes(16));
            $this->setValue($value);
        }

        return parent::beforeSave();
    }
}
