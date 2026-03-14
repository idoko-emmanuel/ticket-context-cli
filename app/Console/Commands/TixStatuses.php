<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\JiraService;
use App\Services\ProjectStore;
use Illuminate\Console\Command;
use RuntimeException;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * Lists all available Jira statuses for the linked project.
 */
class TixStatuses extends TixCommand
{
    /** @var string */
    protected $signature = 'tix:statuses
                            {project? : Jira project key. Defaults to the linked project.}';

    /** @var string */
    protected $description = 'List all available statuses for a Jira project';

    public function __construct(
        private readonly JiraService $jira,
        private readonly ProjectStore $project,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $projectKey = (string) $this->argument('project') ?: $this->project->getProjectKey();

        if (empty($projectKey)) {
            $this->error('No project key provided and no linked project found. Run: tix link <PROJECT-KEY>');

            return SymfonyCommand::FAILURE;
        }

        $projectKey = strtoupper($projectKey);
        $this->info("Fetching statuses for {$projectKey}...");

        try {
            $statuses = $this->jira->getProjectStatuses($projectKey);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return SymfonyCommand::FAILURE;
        }

        if (empty($statuses)) {
            $this->warn("No statuses found for {$projectKey}.");

            return SymfonyCommand::SUCCESS;
        }

        $this->table(
            ['Status'],
            array_map(fn (string $s): array => [$s], $statuses),
        );

        return SymfonyCommand::SUCCESS;
    }
}
