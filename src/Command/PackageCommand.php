<?php

namespace staticphp\Command;

use staticphp\step\CreatePackages;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'package',
    description: 'Create packages for all extensions or specific packages',
)]
class PackageCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this
            ->addOption('packages', null, InputOption::VALUE_REQUIRED, 'Specify which packages to build (comma-separated)')
            ->addOption('iteration', null, InputOption::VALUE_REQUIRED, 'Specify iteration number to use for packages (overrides auto-detected)')
            ->addOption('bump', null, InputOption::VALUE_NONE, 'Bump each package iteration to (latest published on the remote repo) + 1');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $packageNames = $input->getOption('packages');
        $iteration = $input->getOption('iteration');
        $bump = (bool)$input->getOption('bump');
        $debuginfo = $input->getOption('debuginfo');

        if ($bump && $iteration !== null && $iteration !== '') {
            throw new \InvalidArgumentException('--bump and --iteration are mutually exclusive; pass only one.');
        }

        if ($packageNames) {
            $packageNames = explode(',', $packageNames);
            $output->writeln("Creating packages for: " . implode(', ', $packageNames) . "...");
        } else {
            $output->writeln("Creating packages for all extensions...");
        }

        // All parameters now come from constants set by BaseCommand::initialize()
        $result = CreatePackages::run($packageNames, $iteration, $debuginfo, $bump);

        if ($result) {
            $output->writeln("Package creation completed successfully.");
            $this->cleanupTempDir($output);
            return self::SUCCESS;
        }

        $output->writeln("Package creation failed.");
        $this->cleanupTempDir($output);
        return self::FAILURE;
    }
}
