<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ProjectStore;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Base class for all tix commands.
 *
 * Ensures the Claude skill file exists before any command runs,
 * so Claude Code automatically picks up ticket context from the first
 * interaction — not just after `tix branch`.
 */
abstract class TixCommand extends Command
{
    /**
     * Bootstrap the command — called by the framework before handle().
     */
    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        $this->ensureClaudeSkill();
    }

    /**
     * Write SKILL.md into the context directory so Claude Code automatically
     * loads ticket context at the start of every session.
     * Skips silently if no project root is available or the file already exists.
     */
    private function ensureClaudeSkill(): void
    {
        /** @var ProjectStore $project */
        $project = app(ProjectStore::class);

        if ($project->getProjectRoot() === null) {
            return;
        }

        $skillFile = $project->getContextDir().'/SKILL.md';

        if (file_exists($skillFile)) {
            return;
        }

        file_put_contents($skillFile, $this->skillContent());
        $this->line('<info>Claude skill created at '.ProjectStore::CONTEXT_DIR.'/SKILL.md</info>');
    }

    /**
     * Return the SKILL.md content that describes tix to Claude.
     */
    private function skillContent(): string
    {
        return <<<'SKILL'
---
name: ticket-context
description: "Loads Jira ticket context from .claude/skills/ticket-context/ at session start to inform feature work."
license: MIT
metadata:
  author: ticket-context-cli
---

# Ticket Context

At the start of a session, check if any `*-context.md` files exist in
`.claude/skills/ticket-context/`. If found, read them — they describe the current ticket(s)
being worked on for this branch.

## Available Commands

```
tix sprint                        List my tickets in the active sprint
tix branch <KEY> [KEY...]         Create a git branch and save ticket context
  --no-branch                     Skip branch creation, only save context
  --transition=<STATUS>           Also transition ticket(s) to a status
tix context [KEY...]              Refresh ticket context (auto-detects from branch)
  --transition=<STATUS>           Also transition ticket(s) to a status
tix move [KEY...] <STATUS>        Move ticket(s) to a status (auto-detects from branch)
tix statuses [PROJECT]            List available statuses for the project
tix link <PROJECT-KEY>            Link this directory to a Jira project
tix configure                     Set up Jira credentials
tix health                        Check configuration and connection status
```

SKILL;
    }
}
