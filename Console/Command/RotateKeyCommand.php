<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Console\Command;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Amida\ProductDeltaFeed\Model\Config;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RotateKeyCommand extends Command
{
    public function __construct(
        private readonly WriterInterface $configWriter,
        private readonly TypeListInterface $cacheTypeList
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('amidafeed:key:rotate')
            ->setDescription('Generate and save a new public product delta feed key');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $key = bin2hex(random_bytes(16));
        $this->configWriter->save(Config::XML_PATH_PUBLIC_KEY, $key);
        $this->cacheTypeList->cleanType('config');
        $output->writeln('<info>New feed key:</info> ' . $key);
        return self::SUCCESS;
    }
}
