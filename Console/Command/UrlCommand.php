<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Console\Command;

use Magento\Store\Model\StoreManagerInterface;
use Amida\ProductDeltaFeed\Model\Config;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class UrlCommand extends Command
{
    public function __construct(
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('amidafeed:url')
            ->setDescription('Print public v1 snapshot/changes URLs for all active streams.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $baseUrl = rtrim($this->storeManager->getDefaultStoreView()->getBaseUrl(), '/');
        $key = $this->config->getPublicKey();
        $storeCode = $this->storeManager->getDefaultStoreView()->getCode();

        foreach ($this->config->getActiveStreams() as $stream) {
            $output->writeln(sprintf(
                'snapshot %s: %s/amidafeed/v1/snapshot/key/%s/stream/%s?after_state_id=0&store=%s',
                $stream,
                $baseUrl,
                $key,
                $stream,
                $storeCode
            ));
            $output->writeln(sprintf(
                'changes  %s: %s/amidafeed/v1/changes/key/%s/stream/%s?after_event_id=0&store=%s',
                $stream,
                $baseUrl,
                $key,
                $stream,
                $storeCode
            ));
            $output->writeln('');
        }

        return Command::SUCCESS;
    }
}
