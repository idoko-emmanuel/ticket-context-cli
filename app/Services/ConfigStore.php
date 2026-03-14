<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * Manages global credentials stored in ~/.config/ticket-context/config.json.
 *
 * This file is the single source of truth for Jira connection details when
 * the tool is installed globally. It is never committed to any project repo.
 */
class ConfigStore
{
    private string $configDir;

    private string $configPath;

    public function __construct()
    {
        $home = $_SERVER['HOME'] ?? (string) getenv('HOME');

        if (empty($home)) {
            throw new RuntimeException('Could not determine home directory. Is $HOME set?');
        }

        $this->configDir = $home.'/.config/ticket-context';
        $this->configPath = $this->configDir.'/config.json';
    }

    /**
     * Return the path to the global config file (for display purposes).
     */
    public function getConfigPath(): string
    {
        return $this->configPath;
    }

    /**
     * Whether Jira credentials have been saved.
     */
    public function isConfigured(): bool
    {
        $credentials = $this->getJiraCredentials();

        return ! empty($credentials['base_url'])
            && ! empty($credentials['email'])
            && ! empty($credentials['token']);
    }

    /**
     * Read Jira credentials from the global config, falling back to Laravel env config.
     *
     * @return array{base_url: string, email: string, token: string}
     */
    public function getJiraCredentials(): array
    {
        $stored = $this->read();

        return [
            'base_url' => (string) ($stored['jira']['base_url'] ?? config('jira.base_url') ?? ''),
            'email' => (string) ($stored['jira']['email'] ?? config('jira.email') ?? ''),
            'token' => (string) ($stored['jira']['token'] ?? config('jira.token') ?? ''),
        ];
    }

    /**
     * Persist Jira credentials to the global config file.
     */
    public function saveJiraCredentials(string $baseUrl, string $email, string $token): void
    {
        $data = $this->read();
        $data['jira'] = [
            'base_url' => rtrim($baseUrl, '/'),
            'email' => $email,
            'token' => $token,
        ];

        $this->write($data);
    }

    /**
     * Read the raw config array from disk.
     *
     * @return array<string, mixed>
     */
    private function read(): array
    {
        if (! file_exists($this->configPath)) {
            return [];
        }

        $json = file_get_contents($this->configPath);

        if ($json === false || empty($json)) {
            return [];
        }

        return json_decode($json, associative: true) ?? [];
    }

    /**
     * Write the config array to disk, creating the directory if needed.
     *
     * @param  array<string, mixed>  $data
     */
    private function write(array $data): void
    {
        if (! is_dir($this->configDir)) {
            mkdir($this->configDir, 0700, recursive: true);
        }

        file_put_contents(
            $this->configPath,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
        );

        chmod($this->configPath, 0600);
    }
}
