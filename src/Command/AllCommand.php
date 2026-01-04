<?php

namespace staticphp\Command;

use staticphp\step\RunSPC;
use staticphp\step\CreatePackages;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'all',
    description: 'Run both build and package steps',
)]
class AllCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this
            ->addOption('packages', null, InputOption::VALUE_REQUIRED, 'Specify which packages to build (comma-separated)')
            ->addOption('iteration', null, InputOption::VALUE_REQUIRED, 'Specify iteration number to use for packages (overrides auto-detected)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $debug = $input->getOption('debug');
        $packagesOpt = $input->getOption('packages');
        $iteration = $input->getOption('iteration');
        $debuginfo = $input->getOption('debuginfo');
        $phpVersion = SPP_PHP_VERSION; // Get from constant

        // Process packages option
        $packages = null;
        if (is_string($packagesOpt) && $packagesOpt !== '') {
            $packages = array_values(array_filter(array_map('trim', explode(',', $packagesOpt))));
        }

        // Run build step
        $output->writeln("Building PHP with extensions using static-php-cli...");
        $output->writeln("Using PHP version: {$phpVersion}");

        $buildResult = RunSPC::run($debug, $phpVersion, $packages);

        if (!$buildResult) {
            $output->writeln("Build failed.");
            $this->cleanupTempDir($output);
            return self::FAILURE;
        }

        // Run package step
        if ($packages) {
            $output->writeln("Creating packages for: " . implode(', ', $packages) . "...");
        } else {
            $output->writeln("Creating packages for all extensions...");
        }

        // All parameters now come from constants set by BaseCommand::initialize()
        $packageResult = CreatePackages::run($packages, $iteration, $debuginfo);

        if (!$packageResult) {
            $output->writeln("Package creation failed.");
            $this->cleanupTempDir($output);
            return self::FAILURE;
        }

        $output->writeln("All steps completed successfully.");
        $this->cleanupTempDir($output);
        return self::SUCCESS;
    }
}
