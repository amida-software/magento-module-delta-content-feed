<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Console\Command;

use Amida\ProductDeltaFeed\Model\Category\CategorySnapshotRebuilder;
use Amida\ProductDeltaFeed\Model\State\SnapshotRebuilder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SnapshotRebuildCommand extends Command
{
    public function __construct(
        private readonly SnapshotRebuilder $snapshotRebuilder,
        private readonly CategorySnapshotRebuilder $categorySnapshotRebuilder
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('amidafeed:snapshot:rebuild')
            ->setDescription('Rebuild product and category delta state snapshot tables from live Magento data');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $productCount = $this->snapshotRebuilder->rebuild();
        $categoryCount = $this->categorySnapshotRebuilder->rebuild();
        $output->writeln('<info>Snapshot rebuilt for product count:</info> ' . $productCount);
        $output->writeln('<info>Snapshot rebuilt for category count:</info> ' . $categoryCount);
        return self::SUCCESS;
    }
}
