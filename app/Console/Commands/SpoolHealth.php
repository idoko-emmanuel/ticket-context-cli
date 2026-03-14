<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ConfigStore;
use App\Services\JiraService;
use App\Services\ProjectStore;
use Illuminate\Console\Command;
use RuntimeException;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * Displays configuration status and tests the live Jira connection.
 */
class SpoolHealth extends Command
{
    /** @var string */
    protected $signature = 'tix:health';

    /** @var string */
    protected $description = 'Check Jira configuration status and test the connection';

    public function __construct(
        private readonly ConfigStore $config,
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
        $this->info('');
        $this->info('  Ticket Context — Health Check');
        $this->info('');

        // --- Global credentials ---
        $this->line('  <fg=yellow>Global credentials</> ('.$this->config->getConfigPath().')');

        $credentials = $this->config->getJiraCredentials();
        $this->printRow('  Base URL', $credentials['base_url'] ?: '—');
        $this->printRow('  Email', $credentials['email'] ?: '—');
        $this->printRow('  API Token', ! empty($credentials['token']) ? str_repeat('*', 8) : '—');

        $configured = $this->config->isConfigured();
        $this->printStatus('  Credentials', $configured, 'saved', 'not set — run: tix configure');
        $this->info('');

        // --- Live connection test ---
        $this->line('  <fg=yellow>Connection test</>');

        if (! $configured) {
            $this->printStatus('  Jira API', false, '', 'skipped (not configured)');
        } else {
            try {
                $displayName = $this->jira->testConnection();
                $this->printStatus('  Jira API', true, "connected as {$displayName}");
            } catch (RuntimeException $e) {
                $this->printStatus('  Jira API', false, '', $e->getMessage());
            }
        }
        $this->info('');

        // --- Current project ---
        $this->line('  <fg=yellow>Current project</> (cwd: '.getcwd().')');

        if ($this->project->hasProjectConfig()) {
            $projectKey = $this->project->getProjectKey();
            $configFile = $this->project->getProjectConfigPath();
            $this->printStatus('  Project linked', true, $projectKey ?? '(no key set)');
            $this->printRow('  Config file', $configFile ?? '—');

            $branch = $this->project->getCurrentBranch();
            if ($branch !== null) {
                $tickets = $this->project->getTicketsForBranch($branch);
                $this->printRow('  Current branch', $branch);
                $this->printRow('  Linked tickets', ! empty($tickets) ? implode(', ', $tickets) : 'none — run: tix branch <KEY>');
            }
        } else {
            $this->printStatus('  Project linked', false, '', 'no .ticket-context.json found — run: tix link <PROJECT-KEY>');
        }

        $this->info('');

        return SymfonyCommand::SUCCESS;
    }

    private function printRow(string $label, string $value): void
    {
        $this->line(sprintf('  %-20s %s', $label, $value));
    }

    private function printStatus(string $label, bool $ok, string $okText = '', string $failText = 'error'): void
    {
        $icon = $ok ? '<fg=green>✓</>' : '<fg=red>✗</>';
        $text = $ok ? "<fg=green>{$okText}</>" : "<fg=red>{$failText}</>";
        $this->line(sprintf('  %s %-18s %s', $icon, $label, $text));
    }
}
