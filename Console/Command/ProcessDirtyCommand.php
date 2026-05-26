<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Console\Command;

use Amida\ProductDeltaFeed\Model\Category\CategoryChangeProcessor;
use Amida\ProductDeltaFeed\Model\Change\ChangeProcessor;
use Amida\ProductDeltaFeed\Model\Config;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ProcessDirtyCommand extends Command
{
    public function __construct(
        private readonly ChangeProcessor $changeProcessor,
        private readonly CategoryChangeProcessor $categoryChangeProcessor,
        private readonly Config $config
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('amidafeed:process-dirty')
            ->setDescription('Process a batch of product and category delta dirty rows');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $productProcessed = $this->changeProcessor->processBatch($this->config->getDirtyBatchSize());
        $categoryProcessed = $this->categoryChangeProcessor->processBatch($this->config->getDirtyBatchSize());
        $output->writeln('<info>Processed product dirty rows:</info> ' . $productProcessed);
        $output->writeln('<info>Processed category dirty rows:</info> ' . $categoryProcessed);
        return self::SUCCESS;
    }
}
