<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\JiraService;
use App\Services\ProjectStore;
use Illuminate\Console\Command;
use RuntimeException;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * Lists all Jira tickets assigned to the current user in the active sprint.
 * Automatically scoped to the linked project when run inside a linked directory.
 */
class SpoolSprint extends Command
{
    /** @var string */
    protected $signature = 'spool:sprint
                            {--all : Ignore project filter and show all active sprint tickets}
                            {--json : Output raw JSON instead of a table}';

    /** @var string */
    protected $description = 'List all tickets assigned to me in currently open sprint(s)';

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
        $projectKey = $this->option('all') ? null : $this->project->getProjectKey();

        if ($projectKey !== null) {
            $this->info("Filtering by project: {$projectKey}  (use --all to see everything)");
        }

        try {
            $issues = $this->jira->getActiveSprintIssues($projectKey);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return SymfonyCommand::FAILURE;
        }

        if (empty($issues)) {
            $this->info('No tickets found in the active sprint.');

            return SymfonyCommand::SUCCESS;
        }

        if ($this->option('json')) {
            $this->line(json_encode($issues, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return SymfonyCommand::SUCCESS;
        }

        $this->renderTable($issues, $projectKey);

        return SymfonyCommand::SUCCESS;
    }

    /**
     * Render issues as a formatted console table.
     *
     * @param  array<int, array<string, mixed>>  $issues
     */
    private function renderTable(array $issues, ?string $projectKey): void
    {
        $rows = array_map(function (array $issue): array {
            $fields = $issue['fields'] ?? [];
            $summary = (string) ($fields['summary'] ?? '');

            if (mb_strlen($summary) > 60) {
                $summary = mb_substr($summary, 0, 57).'...';
            }

            return [
                $issue['key'] ?? '—',
                $summary,
                $fields['status']['name'] ?? '—',
                $fields['issuetype']['name'] ?? '—',
                $fields['priority']['name'] ?? '—',
            ];
        }, $issues);

        $heading = $projectKey !== null
            ? "Active sprint — {$projectKey}"
            : 'Active sprint — all projects';

        $this->info('');
        $this->info("  {$heading}");
        $this->table(
            ['Key', 'Summary', 'Status', 'Type', 'Priority'],
            $rows,
        );
        $this->info(\count($issues).' ticket(s) found.');
    }
}
