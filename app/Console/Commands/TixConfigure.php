<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ConfigStore;
use App\Services\JiraService;
use Illuminate\Console\Command;
use RuntimeException;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

use function Laravel\Prompts\password;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\text;

/**
 * Interactively configure Jira credentials and persist them to the global config.
 */
class TixConfigure extends TixCommand
{
    /** @var string */
    protected $signature = 'tix:configure';

    /** @var string */
    protected $description = 'Set up your Jira credentials (saved to ~/.config/ticket-context/config.json)';

    /**
     * Jira Cloud base URL template — the subdomain is the only variable part.
     * e.g. "lantansquad" → "https://lantansquad.atlassian.net"
     */
    private const BASE_URL_TEMPLATE = 'https://%s.atlassian.net';

    public function __construct(
        private readonly ConfigStore $config,
        private readonly JiraService $jira,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('');
        $this->info('  Ticket Context — Jira Setup');
        $this->info('  Credentials saved to: '.$this->config->getConfigPath());
        $this->info('');

        $existing = $this->config->getJiraCredentials();
        $existingSubdomain = $this->extractSubdomain($existing['base_url']);

        $subdomain = text(
            label: 'Atlassian subdomain',
            placeholder: 'yourcompany',
            default: $existingSubdomain,
            required: true,
            hint: 'Only the part before .atlassian.net — e.g. "lantansquad"',
            validate: fn (string $value) => $this->validateSubdomain($value),
        );

        $baseUrl = sprintf(self::BASE_URL_TEMPLATE, strtolower(trim($subdomain)));

        $this->line("  Base URL: <fg=cyan>{$baseUrl}</>");
        $this->info('');

        $email = text(
            label: 'Jira account email',
            placeholder: 'you@company.com',
            default: $existing['email'],
            required: true,
        );

        $token = password(
            label: 'Jira API token',
            placeholder: 'Paste your token here',
            required: true,
            hint: 'Create one at https://id.atlassian.com/manage-profile/security/api-tokens',
        );

        $this->config->saveJiraCredentials($baseUrl, $email, $token);

        $this->info('Credentials saved. Testing connection...');

        try {
            $displayName = spin(
                fn () => $this->jira->testConnection(),
                'Connecting to Jira...',
            );

            $this->info('');
            $this->info("  Connected! Authenticated as: {$displayName}");
            $this->info('');
            $this->info("  Run 'spool sprint' to see your active tickets.");
            $this->info("  Run 'spool link <PROJECT-KEY>' inside a repo to link it to a project.");

            return SymfonyCommand::SUCCESS;
        } catch (RuntimeException $e) {
            $this->error('Connection failed: '.$e->getMessage());
            $this->info('Your credentials were saved — re-run tix configure to correct them.');

            return SymfonyCommand::FAILURE;
        }
    }

    /**
     * Extract the subdomain from a stored base URL.
     *
     * Handles "https://acme.atlassian.net", "https://acme.atlassian.net/jira", or bare "acme".
     */
    private function extractSubdomain(string $baseUrl): string
    {
        if (empty($baseUrl)) {
            return '';
        }

        if (preg_match('/https?:\/\/([^.]+)\.atlassian\.net/', $baseUrl, $matches)) {
            return $matches[1];
        }

        return $baseUrl;
    }

    /**
     * Validate that the value is a plain subdomain with no URL parts.
     */
    private function validateSubdomain(string $value): ?string
    {
        $value = trim($value);

        if (str_contains($value, '.') || str_contains($value, '/') || str_contains($value, ' ')) {
            return 'Enter only the subdomain (e.g. "lantansquad"), not the full URL.';
        }

        if (! preg_match('/^[a-z0-9-]+$/i', $value)) {
            return 'Subdomain may only contain letters, numbers, and hyphens.';
        }

        return null;
    }
}
