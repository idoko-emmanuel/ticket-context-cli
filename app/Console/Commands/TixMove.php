<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\JiraService;
use App\Services\ProjectStore;
use Illuminate\Console\Command;
use RuntimeException;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * Transitions one or more Jira tickets to a given status.
 *
 * Keys are detected by the PROJ-123 pattern. Everything else is joined as the status name.
 * When no keys are given, tickets linked to the current branch are used.
 *
 * Examples:
 *   tix move In Progress              (branch tickets → In Progress)
 *   tix move LTN-123 In Review        (single key)
 *   tix move LTN-123 LTN-124 Done     (multiple keys)
 */
class TixMove extends TixCommand
{
    /** @var string */
    protected $signature = 'tix:move {args* : Issue key(s) and status name, e.g. LTN-123 In Progress}';

    /** @var string */
    protected $description = 'Move ticket(s) to a given status — auto-detects from branch when no key given';

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
        /** @var string[] $args */
        $args = (array) $this->argument('args');

        [$keys, $statusWords] = $this->parseArgs($args);

        if (empty($statusWords)) {
            $this->error('No status provided. Example: tix move In Progress');

            return SymfonyCommand::FAILURE;
        }

        $toStatus = implode(' ', $statusWords);

        if (empty($keys)) {
            $keys = $this->keysFromBranch();
        }

        if (empty($keys)) {
            $this->error('No ticket keys provided and none linked to the current branch.');
            $this->info('Run: tix move <KEY> <STATUS>  or  tix branch <KEY> to link one first.');

            return SymfonyCommand::FAILURE;
        }

        $exitCode = SymfonyCommand::SUCCESS;

        foreach ($keys as $key) {
            $this->info("Moving {$key} to \"{$toStatus}\"...");

            try {
                $transitioned = $this->jira->transitionIssue($key, $toStatus);
            } catch (RuntimeException $e) {
                $this->error("{$key}: ".$e->getMessage());
                $exitCode = SymfonyCommand::FAILURE;

                continue;
            }

            if ($transitioned) {
                $this->info("{$key} moved to {$toStatus}.");
            } else {
                $this->warn("{$key}: no transition to \"{$toStatus}\" available. Run: tix statuses");
                $exitCode = SymfonyCommand::FAILURE;
            }
        }

        return $exitCode;
    }

    /**
     * Split raw args into keys (matching PROJ-123) and status words (everything else).
     *
     * @param  string[]  $args
     * @return array{0: string[], 1: string[]}
     */
    private function parseArgs(array $args): array
    {
        $keys = [];
        $statusWords = [];

        foreach ($args as $arg) {
            if (preg_match('/^[A-Z]+-\d+$/i', $arg)) {
                $keys[] = strtoupper($arg);
            } else {
                $statusWords[] = $arg;
            }
        }

        return [$keys, $statusWords];
    }

    /**
     * Return tickets linked to the current git branch via .ticket-context.json.
     *
     * @return string[]
     */
    private function keysFromBranch(): array
    {
        $branch = $this->project->getCurrentBranch();

        if ($branch === null) {
            return [];
        }

        $keys = $this->project->getTicketsForBranch($branch);

        if (! empty($keys)) {
            $this->info("Auto-detected tickets from branch '{$branch}': ".implode(', ', $keys));
        }

        return $keys;
    }
}
