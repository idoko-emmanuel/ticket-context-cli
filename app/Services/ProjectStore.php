<?php

declare(strict_types=1);

namespace App\Services;

use Symfony\Component\Process\Process;

/**
 * Manages per-project configuration stored in .ticket-context.json.
 *
 * The file is discovered by walking up from the current working directory,
 * similar to how git finds .git/. This means the tool automatically scopes
 * to the right project regardless of which subdirectory you're in.
 *
 * File format:
 * {
 *   "project": "PROJ",
 *   "branches": {
 *     "feature/proj-123-my-feature": ["PROJ-123"],
 *     "feature/proj-124-multi": ["PROJ-124", "PROJ-125"]
 *   }
 * }
 */
class ProjectStore
{
    private const CONFIG_FILE = '.ticket-context.json';

    /** Directory (relative to project root) where context files are stored. */
    public const CONTEXT_DIR = '.ticket-context';

    /** Absolute path to the discovered .ticket-context.json, or null if none found. */
    private ?string $projectConfigPath;

    /** Cached config data. */
    private ?array $data = null;

    public function __construct()
    {
        $this->projectConfigPath = $this->discoverConfigFile((string) getcwd());
    }

    /**
     * Whether a .ticket-context.json has been found for the current directory.
     */
    public function hasProjectConfig(): bool
    {
        return $this->projectConfigPath !== null;
    }

    /**
     * Return the path of the discovered project config file.
     */
    public function getProjectConfigPath(): ?string
    {
        return $this->projectConfigPath;
    }

    /**
     * Return the project root directory (directory containing .ticket-context.json).
     */
    public function getProjectRoot(): ?string
    {
        if ($this->projectConfigPath === null) {
            return null;
        }

        return dirname($this->projectConfigPath);
    }

    /**
     * Return the absolute path to the .ticket-context/ context directory,
     * creating it if it does not exist. Falls back to cwd if no project config.
     */
    public function getContextDir(): string
    {
        $root = $this->getProjectRoot() ?? (string) getcwd();
        $dir = $root.'/'.self::CONTEXT_DIR;

        if (! is_dir($dir)) {
            mkdir($dir, 0755, recursive: true);
        }

        return $dir;
    }

    /**
     * Return the Jira project key linked to the current directory.
     */
    public function getProjectKey(): ?string
    {
        return $this->get('project');
    }

    /**
     * Link the current directory to a Jira project key and create .ticket-context.json.
     */
    public function linkProject(string $projectKey): void
    {
        if ($this->projectConfigPath === null) {
            $this->projectConfigPath = (string) getcwd().'/'.self::CONFIG_FILE;
        }

        $this->set('project', strtoupper($projectKey));
    }

    /**
     * Associate one or more ticket keys with the given branch name.
     *
     * @param  string[]  $ticketKeys
     */
    public function linkTicketsToBranch(string $branch, array $ticketKeys): void
    {
        $branches = (array) ($this->read()['branches'] ?? []);
        $branches[$branch] = array_map('strtoupper', $ticketKeys);

        $this->set('branches', $branches);
    }

    /**
     * Return the ticket keys linked to the given branch.
     *
     * @return string[]
     */
    public function getTicketsForBranch(string $branch): array
    {
        $branches = (array) ($this->read()['branches'] ?? []);

        return (array) ($branches[$branch] ?? []);
    }

    /**
     * Detect the current git branch name.
     */
    public function getCurrentBranch(): ?string
    {
        $process = new Process(['git', 'rev-parse', '--abbrev-ref', 'HEAD']);
        $process->run();

        if (! $process->isSuccessful()) {
            return null;
        }

        $branch = trim($process->getOutput());

        return $branch !== '' && $branch !== 'HEAD' ? $branch : null;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Walk up from the given directory looking for .ticket-context.json.
     */
    private function discoverConfigFile(string $startDir): ?string
    {
        $dir = $startDir;

        while (true) {
            $candidate = $dir.'/'.self::CONFIG_FILE;

            if (file_exists($candidate)) {
                return $candidate;
            }

            $parent = dirname($dir);

            if ($parent === $dir) {
                break; // Reached filesystem root.
            }

            $dir = $parent;
        }

        return null;
    }

    /**
     * Read config data from disk, using cached copy if available.
     *
     * @return array<string, mixed>
     */
    private function read(): array
    {
        if ($this->data !== null) {
            return $this->data;
        }

        if ($this->projectConfigPath === null || ! file_exists($this->projectConfigPath)) {
            return $this->data = [];
        }

        $json = file_get_contents($this->projectConfigPath);

        $this->data = ($json !== false && $json !== '')
            ? (json_decode($json, associative: true) ?? [])
            : [];

        return $this->data;
    }

    /**
     * Set a top-level key and persist to disk.
     */
    private function set(string $key, mixed $value): void
    {
        $data = $this->read();
        $data[$key] = $value;
        $this->data = $data;

        file_put_contents(
            (string) $this->projectConfigPath,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
        );
    }

    /**
     * Get a top-level key from config data.
     */
    private function get(string $key): mixed
    {
        return $this->read()[$key] ?? null;
    }
}
