<?php

declare(strict_types=1);

use App\Services\ConfigStore;
use App\Services\JiraService;

use function Pest\Laravel\mock;

beforeEach(function (): void {
    $this->config = mock(ConfigStore::class);
    $this->jira = mock(JiraService::class);

    $this->config->shouldReceive('getConfigPath')->andReturn('/home/user/.config/ticket-context/config.json')->byDefault();
    $this->config->shouldReceive('getJiraCredentials')->andReturn([
        'base_url' => '',
        'email' => '',
        'token' => '',
    ])->byDefault();
});

// ─── 13.1 Successful configuration ───────────────────────────────────────

it('prompts for subdomain, email, and token then saves credentials and exits SUCCESS', function (): void {
    $this->config->shouldReceive('saveJiraCredentials')
        ->with('https://acme.atlassian.net', 'user@acme.com', 'my-api-token')
        ->once();

    $this->jira->shouldReceive('testConnection')->andReturn('Alice Smith');

    $this->artisan('tix:configure')
        ->expectsQuestion('Atlassian subdomain', 'acme')
        ->expectsQuestion('Jira account email', 'user@acme.com')
        ->expectsQuestion('Jira API token', 'my-api-token')
        ->expectsOutputToContain('Alice Smith')
        ->assertSuccessful();
});

// ─── 13.2 Invalid subdomain — full URL ────────────────────────────────────

it('fails when subdomain contains a dot (full URL provided)', function (): void {
    // Validation throws via Symfony fallback — command exits FAILURE
    $this->config->shouldReceive('saveJiraCredentials')->never();

    $this->artisan('tix:configure')
        ->expectsQuestion('Atlassian subdomain', 'my.company')
        ->assertFailed();
});

// ─── 13.3 Invalid subdomain — special characters ──────────────────────────

it('fails when subdomain contains a space', function (): void {
    $this->config->shouldReceive('saveJiraCredentials')->never();

    $this->artisan('tix:configure')
        ->expectsQuestion('Atlassian subdomain', 'my company')
        ->assertFailed();
});

// ─── 13.4 Valid subdomain formats ────────────────────────────────────────

it('assembles correct base URL from alphanumeric subdomain', function (): void {
    $this->config->shouldReceive('saveJiraCredentials')
        ->with('https://lantansquad.atlassian.net', 'user@acme.com', 'token')
        ->once();

    $this->jira->shouldReceive('testConnection')->andReturn('Alice');

    $this->artisan('tix:configure')
        ->expectsQuestion('Atlassian subdomain', 'lantansquad')
        ->expectsQuestion('Jira account email', 'user@acme.com')
        ->expectsQuestion('Jira API token', 'token')
        ->expectsOutputToContain('https://lantansquad.atlassian.net')
        ->assertSuccessful();
});

it('assembles correct base URL from hyphenated subdomain', function (): void {
    $this->config->shouldReceive('saveJiraCredentials')
        ->with('https://my-company.atlassian.net', 'user@acme.com', 'token')
        ->once();

    $this->jira->shouldReceive('testConnection')->andReturn('Alice');

    $this->artisan('tix:configure')
        ->expectsQuestion('Atlassian subdomain', 'my-company')
        ->expectsQuestion('Jira account email', 'user@acme.com')
        ->expectsQuestion('Jira API token', 'token')
        ->assertSuccessful();
});

// ─── 13.5 Connection failure after save ──────────────────────────────────

it('saves credentials but exits FAILURE when connection test fails', function (): void {
    $this->config->shouldReceive('saveJiraCredentials')->once();
    $this->jira->shouldReceive('testConnection')->andThrow(new RuntimeException('Authentication failed'));

    $this->artisan('tix:configure')
        ->expectsQuestion('Atlassian subdomain', 'acme')
        ->expectsQuestion('Jira account email', 'user@acme.com')
        ->expectsQuestion('Jira API token', 'bad-token')
        ->expectsOutputToContain('Connection failed')
        ->assertFailed();
});
