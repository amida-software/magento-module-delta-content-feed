<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Console\Command;

use Amida\ProductDeltaFeed\Model\State\SnapshotRebuilder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SnapshotRebuildCommand extends Command
{
    public function __construct(private readonly SnapshotRebuilder $snapshotRebuilder)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('amidafeed:snapshot:rebuild')
            ->setDescription('Rebuild the product delta state snapshot table from live Magento data');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $processed = $this->snapshotRebuilder->rebuild();
        $output->writeln('<info>Snapshot rebuilt for product count:</info> ' . $processed);
        return self::SUCCESS;
    }
}
