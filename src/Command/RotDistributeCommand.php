<?php
namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RotDistributeCommand extends Command
{
    protected function configure()
    {
        $this->setName('rot:distribute');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln('Distribute');
    }
}
