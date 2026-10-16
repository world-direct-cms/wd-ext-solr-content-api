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

#[AsCommand(
    name: 'solr-content-api:file-export',
    description: 'Solr Content API - File Export'
)]
final class FileExportCommand extends Command
{
    public function __construct(private readonly SolrExportService $solrExportService)
    {
        parent::__construct();
    }

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
