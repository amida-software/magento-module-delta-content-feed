<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Test\Integration\Controller;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\TestCase\AbstractController;
use Amida\ProductDeltaFeed\Model\Config;

abstract class AbstractFeedControllerTest extends AbstractController
{
    protected WriterInterface $configWriter;
    protected TypeListInterface $cacheTypeList;
    protected ResourceConnection $resourceConnection;

    protected function setUp(): void
    {
        parent::setUp();
        $objectManager = Bootstrap::getObjectManager();
        $this->configWriter = $objectManager->get(WriterInterface::class);
        $this->cacheTypeList = $objectManager->get(TypeListInterface::class);
        $this->resourceConnection = $objectManager->get(ResourceConnection::class);

        $this->configWriter->save(Config::XML_PATH_ENABLED, 1);
        $this->configWriter->save(Config::XML_PATH_ROUTE_ENABLED, 1);
        $this->configWriter->save(Config::XML_PATH_API_REQUEST_MONOPOLY_ENABLED, 0);
        $this->configWriter->save(Config::XML_PATH_PUBLIC_KEY, 'integration-key');
        $this->configWriter->save(Config::XML_PATH_ZSTD_ENABLED, 0);
        $this->configWriter->save(Config::XML_PATH_STORE_ENDPOINT_ENABLED, 1);
        $this->configWriter->save('amida_productdeltafeed/streams/attributes_enabled', 1);
        $this->cacheTypeList->cleanType('config');
        $this->truncateTables();
    }

    protected function tearDown(): void
    {
        $this->truncateTables();
        parent::tearDown();
    }

    protected function truncateTables(): void
    {
        $connection = $this->resourceConnection->getConnection();
        foreach (['amida_product_delta_dirty', 'amida_product_delta_event', 'amida_product_delta_state', 'amida_product_delta_dead_letter', 'amida_product_delta_category_dirty', 'amida_product_delta_category_event', 'amida_product_delta_category_state'] as $table) {
            $connection->delete($this->resourceConnection->getTableName($table));
        }
    }
}
