<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\JiraService;
use App\Services\ProjectStore;
use Illuminate\Console\Command;
use RuntimeException;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Process\Process;

/**
 * Creates a git feature branch, links one or more tickets to it,
 * and saves full context to a Markdown file.
 */
class SpoolBranch extends Command
{
    /** @var string */
    protected $signature = 'spool:branch
                            {keys* : One or more Jira issue keys, e.g. PROJ-123 PROJ-124}
                            {--no-branch : Skip git branch creation and only save context file}';

    /** @var string */
    protected $description = 'Create a git branch, link ticket(s) to it, and save context to a Markdown file';

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
        /** @var string[] $rawKeys */
        $rawKeys = (array) $this->argument('keys');
        $keys = array_map('strtoupper', array_filter($rawKeys));

        if (empty($keys)) {
            $this->error('Please provide at least one Jira issue key.');

            return SymfonyCommand::FAILURE;
        }

        // Fetch the primary ticket to build the branch name.
        $primaryKey = $keys[0];
        $this->info("Fetching {$primaryKey}...");

        try {
            $primaryIssue = $this->jira->getIssue($primaryKey);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return SymfonyCommand::FAILURE;
        }

        $summary = (string) ($primaryIssue['fields']['summary'] ?? '');
        $branchName = $this->buildBranchName($primaryKey, $summary);
        $contextDir = $this->project->getContextDir();
        $filename = "{$contextDir}/{$primaryKey}-context.md";

        if (! $this->option('no-branch')) {
            if (! $this->createBranch($branchName)) {
                return SymfonyCommand::FAILURE;
            }
        }

        // Link all tickets to the branch in .ticket-context.json.
        if ($this->project->hasProjectConfig()) {
            $activeBranch = $this->project->getCurrentBranch() ?? $branchName;
            $this->project->linkTicketsToBranch($activeBranch, $keys);
            $this->info('Linked '.implode(', ', $keys)." → branch '{$activeBranch}'");
        } else {
            $this->warn('No .ticket-context.json found — ticket not persisted. Run: spool link <PROJECT-KEY>');
        }

        // Build and save context for all tickets.
        /** @var SpoolContext $contextCommand */
        $contextCommand = app(SpoolContext::class);

        $markdown = \count($keys) === 1
            ? $contextCommand->buildMarkdown($primaryIssue, $primaryKey)
            : $this->buildMultiTicketMarkdown($contextCommand, $keys, $primaryIssue, $primaryKey);

        file_put_contents($filename, $markdown);
        $this->info('Context saved to '.ProjectStore::CONTEXT_DIR.'/'.$primaryKey.'-context.md');

        return SymfonyCommand::SUCCESS;
    }

    /**
     * Build a combined Markdown file for multiple tickets.
     *
     * @param  string[]  $keys
     * @param  array<string, mixed>  $primaryIssue
     */
    private function buildMultiTicketMarkdown(
        SpoolContext $contextCommand,
        array $keys,
        array $primaryIssue,
        string $primaryKey,
    ): string {
        $sections = [];

        $sections[] = $contextCommand->buildMarkdown($primaryIssue, $primaryKey);

        foreach (\array_slice($keys, 1) as $key) {
            $this->info("Fetching {$key}...");

            try {
                $issue = $this->jira->getIssue($key);
                $sections[] = $contextCommand->buildMarkdown($issue, $key);
            } catch (RuntimeException $e) {
                $this->warn("Could not fetch {$key}: ".$e->getMessage());
            }
        }

        return implode("\n\n---\n\n", $sections);
    }

    /**
     * Build a git branch name from the primary issue key and summary.
     *
     * Format: feature/{lowercase-key}-{kebab-slug-first-40-chars}
     */
    private function buildBranchName(string $key, string $summary): string
    {
        $slug = strtolower($summary);
        $slug = preg_replace('/[^a-z0-9\s-]/', '', $slug) ?? $slug;
        $slug = preg_replace('/[\s-]+/', '-', trim($slug)) ?? $slug;
        $slug = substr($slug, 0, 40);
        $slug = rtrim($slug, '-');

        return 'feature/'.strtolower($key).'-'.$slug;
    }

    /**
     * Run `git checkout -b {branch}` in the current working directory.
     */
    private function createBranch(string $branchName): bool
    {
        $this->info("Creating branch: {$branchName}");

        $process = new Process(['git', 'checkout', '-b', $branchName]);
        $process->run();

        if (! $process->isSuccessful()) {
            $this->error('Git branch creation failed: '.trim($process->getErrorOutput()));

            return false;
        }

        $this->info("Switched to new branch '{$branchName}'");

        return true;
    }
}
