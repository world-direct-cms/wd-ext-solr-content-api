<?php

declare(strict_types=1);

namespace WorldDirect\SolrContentApi\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;
use WorldDirect\SolrContentApi\Service\SolrExportService;

/**
 * Generates the Solr JSON export through the TYPO3 command-line interface.
 */
#[AsCommand(
    name: 'solr-content-api:file-export',
    description: 'Solr Content API - File Export'
)]
final class FileExportCommand extends Command
{
    /**
     * Initializes the command with the export service.
     *
     * @param SolrExportService $solrExportService Service that creates the export file.
     */
    public function __construct(private readonly SolrExportService $solrExportService)
    {
        parent::__construct();
    }

    /**
     * Runs the export and reports its outcome without exposing exception details.
     *
     * @param InputInterface $input Console input used to initialize styled output.
     * @param OutputInterface $output Console output for success and failure messages.
     * @return int Command::SUCCESS on completion or Command::FAILURE on an export error.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $path = $this->solrExportService->export();
        } catch (Throwable) {
            $io->error('File export failed. Check the TYPO3 log for details.');
            return Command::FAILURE;
        }

        $io->success(sprintf('Solr Content API - File Export written to "%s".', $path));
        return Command::SUCCESS;
    }
}
