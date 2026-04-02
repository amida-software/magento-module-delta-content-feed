<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Console\Command;

use Amida\ProductDeltaFeed\Model\ProductProjectionService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RebuildCommand extends Command
{
    public function __construct(private readonly ProductProjectionService $projectionService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('amidafeed:rebuild')
            ->setDescription('Rebuild Amida Product Delta Feed snapshots and optionally emit events.')
            ->addOption('product-id', null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Specific product IDs')
            ->addOption('stream', null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Limit to stream(s): content, seo, price, availability, category')
            ->addOption('emit-events', null, InputOption::VALUE_NONE, 'Emit changelog events during rebuild');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $productIds = array_map('intval', (array)$input->getOption('product-id'));
        $streams = array_map('strval', (array)$input->getOption('stream'));
        $emitEvents = (bool)$input->getOption('emit-events');

        $this->projectionService->rebuild($productIds ?: null, $streams ?: null, $emitEvents);
        $output->writeln('<info>Rebuild completed.</info>');
        return Command::SUCCESS;
    }
}
