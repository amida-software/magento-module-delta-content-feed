<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Model\State;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;
use Amida\ProductDeltaFeed\Model\AttributeSelector;
use Amida\ProductDeltaFeed\Model\Config;
use Amida\ProductDeltaFeed\Model\Offer\DirectSqlOfferProvider;
use Amida\ProductDeltaFeed\Model\State\CuratedProductBuilder;

class ProductStateBuilder
{
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly AttributeSelector $attributeSelector,
        private readonly Config $config,
        private readonly EavConfig $eavConfig,
        private readonly DirectSqlOfferProvider $offerProvider,
        private readonly ResourceConnection $resourceConnection,
        private readonly StoreManagerInterface $storeManager,
        private readonly CuratedProductBuilder $curatedProductBuilder
    ) {
    }

    public function buildStates(int $productId, string $storeCode): ?array
    {
        try {
            $store = $this->storeManager->getStore($storeCode);
            $product = $this->productRepository->getById($productId, false, (int)$store->getId(), true);
        } catch (\Throwable) {
            return null;
        }

        $sku = (string)$product->getSku();
        $enabled = (int)$product->getStatus() === Status::STATUS_ENABLED;
        $sourceUpdatedAt = (string)($product->getUpdatedAt() ?? '');
        $offer = $this->offerProvider->buildByProductId($productId, $storeCode, $enabled)
            ?: $this->fallbackOffer($productId, $sku, $storeCode, $enabled, $sourceUpdatedAt);
        $offerData = (array)($offer['offer'] ?? []);
        $price = $this->priceStateFromOffer($offerData);
        $availability = $this->availabilityStateFromOffer($offerData);

        return [
            'meta' => [
                'product_id' => $productId,
                'sku' => $sku,
                'enabled' => $enabled,
                'source_updated_at' => $sourceUpdatedAt,
                'store_code' => $storeCode,
            ],
            'content' => [
                'enabled' => $enabled,
                'attributes' => $this->buildAttributePayload($product, $this->attributeSelector->getContentAttributeCodes()),
                'deleted' => false,
            ],
            'seo' => [
                'enabled' => $enabled,
                'attributes' => $this->buildAttributePayload($product, $this->config->getSeoAttributeCodes()),
                'deleted' => false,
            ],
            'price' => [
                'enabled' => $enabled,
                'price' => $price,
                'deleted' => false,
            ],
            'availability' => [
                'enabled' => $enabled,
                'availability' => $availability,
                'deleted' => false,
            ],
            'offer' => $offer,
            'category' => [
                'enabled' => $enabled,
                'category' => $this->buildCategoryState($productId),
                'deleted' => false,
            ],
            'curated' => $this->curatedProductBuilder->build($product, $storeCode, [
                'offer' => $offerData,
                'availability' => $availability,
            ]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fallbackOffer(int $productId, string $sku, string $storeCode, bool $enabled, string $sourceUpdatedAt): array
    {
        return [
            'enabled' => $enabled,
            'deleted' => false,
            'offer' => [
                'product_id' => $productId,
                'sku' => $sku,
                'prices' => [
                    'old' => null,
                    'current' => null,
                    'currency' => (string)$this->storeManager->getStore($storeCode)->getCurrentCurrencyCode(),
                    'source' => 'direct_sql_fallback',
                ],
                'availability' => $enabled ? 'out_of_stock' : 'hidden',
                'qty' => 0.0,
                'is_salable' => false,
                'is_in_stock' => false,
                'stock_status' => 'out_of_stock',
                'source_updated_at' => $sourceUpdatedAt,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $offer
     * @return array<string, mixed>
     */
    private function priceStateFromOffer(array $offer): array
    {
        $prices = (array)($offer['prices'] ?? []);
        return [
            'price' => array_key_exists('old', $prices) && $prices['old'] !== null ? (float)$prices['old'] : null,
            'special_price' => array_key_exists('special_price', $prices) && $prices['special_price'] !== null ? (float)$prices['special_price'] : null,
            'special_from_date' => (string)($prices['special_from_date'] ?? ''),
            'special_to_date' => (string)($prices['special_to_date'] ?? ''),
            'tier_prices' => [],
            'group_prices' => [],
            'currency_code' => (string)($prices['currency'] ?? ''),
            'current' => array_key_exists('current', $prices) && $prices['current'] !== null ? (float)$prices['current'] : null,
            'source' => (string)($prices['source'] ?? 'direct_sql_eav'),
        ];
    }

    /**
     * @param array<string, mixed> $offer
     * @return array<string, mixed>
     */
    private function availabilityStateFromOffer(array $offer): array
    {
        return [
            'is_in_stock' => (bool)($offer['is_in_stock'] ?? false),
            'is_salable' => (bool)($offer['is_salable'] ?? false),
            'qty' => (float)($offer['qty'] ?? 0.0),
            'manage_stock' => (bool)($offer['manage_stock'] ?? true),
            'backorders' => (int)($offer['backorders'] ?? 0),
            'stock_status' => (string)($offer['stock_status'] ?? 'out_of_stock'),
            'availability' => (string)($offer['availability'] ?? 'out_of_stock'),
            'source' => 'direct_sql_inventory',
        ];
    }

    /**
     * @param string[] $attributeCodes
     * @return array<int, array<string, mixed>>
     */
    private function buildAttributePayload(Product $product, array $attributeCodes): array
    {
        $payload = [];
        foreach ($attributeCodes as $code) {
            $payload[] = $this->normalizeAttribute($product, $code);
        }

        usort(
            $payload,
            static fn (array $left, array $right): int => strcmp((string)$left['code'], (string)$right['code'])
        );

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeAttribute(Product $product, string $code): array
    {
        $attribute = $this->eavConfig->getAttribute(Product::ENTITY, $code);
        $frontendInput = (string)$attribute->getFrontendInput();
        $value = $product->getData($code);

        $result = [
            'code' => $code,
            'kind' => $this->kindFromFrontendInput($frontendInput, $value),
            'is_null' => $value === null,
            'labels' => [],
            'list_values' => [],
        ];

        if ($value === null) {
            return $result;
        }

        switch ($frontendInput) {
            case 'select':
                $result['list_values'] = [(string)$value];
                $text = $product->getAttributeText($code);
                $result['labels'] = $this->normalizeLabels($text);
                break;
            case 'multiselect':
                $result['list_values'] = array_values(array_filter(array_map('trim', explode(',', (string)$value)), static fn (string $item): bool => $item !== ''));
                $text = $product->getAttributeText($code);
                $result['labels'] = $this->normalizeLabels($text);
                break;
            case 'boolean':
                $result['bool_value'] = (bool)$value;
                break;
            case 'price':
            case 'weight':
                $result['float_value'] = (float)$value;
                break;
            case 'date':
                $result['string_value'] = (string)$value;
                break;
            case 'textarea':
                $result['string_value'] = (string)$value;
                $result['kind'] = 'text';
                break;
            default:
                if (is_array($value)) {
                    $result['string_value'] = json_encode($value, JSON_UNESCAPED_UNICODE);
                } elseif (is_bool($value)) {
                    $result['bool_value'] = $value;
                } elseif (is_int($value)) {
                    $result['int_value'] = $value;
                } elseif (is_float($value)) {
                    $result['float_value'] = $value;
                } elseif (is_numeric($value) && str_contains((string)$value, '.')) {
                    $result['float_value'] = (float)$value;
                } elseif (is_numeric($value)) {
                    $result['int_value'] = (int)$value;
                } else {
                    $result['string_value'] = (string)$value;
                }
                break;
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCategoryState(int $productId): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('catalog_category_product');
        $select = $connection->select()
            ->from($table, ['category_id', 'position'])
            ->where('product_id = ?', $productId)
            ->order('category_id ASC');
        $rows = $connection->fetchAll($select);

        $categories = [];
        foreach ($rows as $row) {
            $categories[] = [
                'category_id' => (int)$row['category_id'],
                'position' => (int)$row['position'],
            ];
        }

        return [
            'categories' => $categories,
            'added_category_ids' => [],
            'removed_category_ids' => [],
        ];
    }

    /**
     * @return string[]
     */
    private function normalizeLabels(mixed $text): array
    {
        if ($text === null) {
            return [];
        }
        if (is_array($text)) {
            return array_values(array_map(static fn (mixed $item): string => (string)$item, $text));
        }
        return [(string)$text];
    }

    private function kindFromFrontendInput(string $frontendInput, mixed $value): string
    {
        return match ($frontendInput) {
            'select' => 'select',
            'multiselect' => 'multiselect',
            'boolean' => 'bool',
            'date' => 'date',
            'price', 'weight' => 'float',
            'textarea' => 'text',
            default => is_bool($value) ? 'bool' : (is_int($value) ? 'int' : (is_float($value) || (is_numeric($value) && str_contains((string)$value, '.')) ? 'float' : 'string')),
        };
    }
}
