<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\JiraService;
use App\Services\ProjectStore;
use Illuminate\Console\Command;
use RuntimeException;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * Fetches and formats full ticket context as Markdown, ready to paste into Claude.
 *
 * When called with no key, auto-detects tickets from the current git branch
 * via .ticket-context.json.
 */
class SpoolContext extends Command
{
    /** @var string */
    protected $signature = 'spool:context
                            {keys?* : Jira issue key(s), e.g. PROJ-123. Omit to use current branch tickets.}
                            {--file : Save context to a Markdown file in the current directory}';

    /** @var string */
    protected $description = 'Fetch and format full ticket context as Markdown — perfect for pasting into Claude';

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
        $keys = $this->resolveKeys();

        if (empty($keys)) {
            $this->error('No ticket keys provided and none linked to the current branch.');
            $this->info('Run: spool context <KEY>  or  spool branch <KEY> to link one first.');

            return SymfonyCommand::FAILURE;
        }

        $sections = [];
        $primaryKey = $keys[0];

        foreach ($keys as $key) {
            $key = strtoupper($key);
            $this->info("Fetching {$key}...");

            try {
                $issue = $this->jira->getIssue($key);
                $sections[$key] = $this->buildMarkdown($issue, $key);
            } catch (RuntimeException $e) {
                $this->error("Could not fetch {$key}: ".$e->getMessage());

                return SymfonyCommand::FAILURE;
            }
        }

        $markdown = implode("\n\n---\n\n", $sections);

        $this->line('');
        $this->line($markdown);

        if ($this->option('file')) {
            $contextDir = $this->project->getContextDir();
            $filename = "{$contextDir}/".strtoupper($primaryKey).'-context.md';
            file_put_contents($filename, $markdown);
            $this->info('Context saved to '.ProjectStore::CONTEXT_DIR.'/'.strtoupper($primaryKey).'-context.md');
        }

        return SymfonyCommand::SUCCESS;
    }

    /**
     * Resolve which ticket keys to fetch.
     *
     * Priority: explicit args → current branch's linked tickets.
     *
     * @return string[]
     */
    private function resolveKeys(): array
    {
        /** @var string[] $args */
        $args = (array) $this->argument('keys');
        $args = array_filter($args);

        if (! empty($args)) {
            return array_values($args);
        }

        // Auto-detect from current branch.
        $branch = $this->project->getCurrentBranch();

        if ($branch === null) {
            return [];
        }

        $linked = $this->project->getTicketsForBranch($branch);

        if (! empty($linked)) {
            $this->info("Auto-detected tickets from branch '{$branch}': ".implode(', ', $linked));
        }

        return $linked;
    }

    /**
     * Build the full Markdown context document for a single Jira issue.
     *
     * @param  array<string, mixed>  $issue
     */
    public function buildMarkdown(array $issue, string $key): string
    {
        $fields = $issue['fields'] ?? [];

        $summary = (string) ($fields['summary'] ?? 'No summary');
        $status = (string) ($fields['status']['name'] ?? 'Unknown');
        $type = (string) ($fields['issuetype']['name'] ?? 'Unknown');
        $priority = (string) ($fields['priority']['name'] ?? 'Unknown');
        $assignee = (string) ($fields['assignee']['displayName'] ?? 'Unassigned');

        $description = $this->jira->descriptionAsMarkdown($issue);
        $comments = $this->jira->extractComments($issue);

        $lines = [];

        $lines[] = "# {$key} - {$summary}";
        $lines[] = '';
        $lines[] = "**Type:** {$type} | **Status:** {$status} | **Priority:** {$priority} | **Assignee:** {$assignee}";
        $lines[] = '';
        $lines[] = '---';
        $lines[] = '';
        $lines[] = '## Description';
        $lines[] = '';
        $lines[] = $description;
        $lines[] = '';

        if (! empty($comments)) {
            $lines[] = '## Comments';
            $lines[] = '';

            foreach ($comments as $comment) {
                $lines[] = "### {$comment['author']} — {$comment['date']}";
                $lines[] = '';
                $lines[] = $comment['body'] ?: '_No content._';
                $lines[] = '';
            }
        }

        return implode("\n", $lines);
    }
}
