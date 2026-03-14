<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * Displays a summary of all tix commands and their options.
 */
class TixHelp extends TixCommand
{
    /** @var string */
    protected $signature = 'tix:help';

    /** @var string */
    protected $description = 'Show all tix commands and their options';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->line('');
        $this->line('<info>tix</info> — Jira ticket context for Claude Code');
        $this->line('');
        $this->line('<comment>Usage:</comment>  tix <command> [options]');
        $this->line('');
        $this->line('<comment>Commands:</comment>');
        $this->line('');

        $this->printCommand(
            'sprint',
            'List tickets assigned to me in the active sprint',
            ['--all   Show all projects, ignoring the linked project filter', '--json  Output raw JSON'],
        );

        $this->printCommand(
            'branch <KEY> [KEY...]',
            'Create a git branch, link ticket(s), and save context',
            ['--no-branch          Skip git branch creation, only save context', '--transition=STATUS  Transition ticket(s) to this status'],
        );

        $this->printCommand(
            'context [KEY...]',
            'Refresh ticket context (auto-detects keys from current branch)',
            ['--transition=STATUS  Transition ticket(s) to this status'],
        );

        $this->printCommand(
            'move [KEY...] <STATUS>',
            'Move ticket(s) to a status (auto-detects keys from current branch)',
        );

        $this->printCommand(
            'statuses [PROJECT]',
            'List available statuses for the project',
        );

        $this->printCommand(
            'link <PROJECT-KEY>',
            'Link this directory to a Jira project',
        );

        $this->printCommand(
            'configure',
            'Set up Jira credentials',
        );

        $this->printCommand(
            'health',
            'Check configuration and Jira connection status',
        );

        $this->line('');

        return SymfonyCommand::SUCCESS;
    }

    /**
     * Print a single command entry with optional flags.
     *
     * @param  string[]  $flags
     */
    private function printCommand(string $usage, string $description, array $flags = []): void
    {
        $this->line("  <info>tix {$usage}</info>");
        $this->line("    {$description}");

        foreach ($flags as $flag) {
            $this->line("    <fg=gray>  {$flag}</>");
        }

        $this->line('');
    }
}
