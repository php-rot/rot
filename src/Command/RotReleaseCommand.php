<?php
namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RotReleaseCommand extends Command
{
    protected function configure()
    {
        $this->setName('rot:release');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln('Release');
    }
}
