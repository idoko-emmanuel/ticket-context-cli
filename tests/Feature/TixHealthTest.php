<?php

declare(strict_types=1);

use App\Services\ConfigStore;
use App\Services\JiraService;
use App\Services\ProjectStore;

use function Pest\Laravel\mock;

beforeEach(function (): void {
    $this->config = mock(ConfigStore::class);
    $this->jira = mock(JiraService::class);
    $this->project = mock(ProjectStore::class);

    $this->config->shouldReceive('getConfigPath')->andReturn('/home/user/.config/ticket-context/config.json')->byDefault();
    $this->config->shouldReceive('getJiraCredentials')->andReturn([
        'base_url' => 'https://acme.atlassian.net',
        'email' => 'user@acme.com',
        'token' => 'secret-token',
    ])->byDefault();
    $this->config->shouldReceive('isConfigured')->andReturn(true)->byDefault();
    $this->jira->shouldReceive('testConnection')->andReturn('Alice')->byDefault();

    $this->project->shouldReceive('hasProjectConfig')->andReturn(false)->byDefault();
    $this->project->shouldReceive('getProjectRoot')->andReturn(null)->byDefault();
    $this->project->shouldReceive('getContextDir')->andReturn(sys_get_temp_dir())->byDefault();
});

// ─── 12.1 Credentials configured ────────────────────────────────────────

it('displays base URL when credentials are configured', function (): void {
    $this->artisan('tix:health')
        ->expectsOutputToContain('https://acme.atlassian.net')
        ->assertSuccessful();
});

it('displays email when credentials are configured', function (): void {
    $this->artisan('tix:health')
        ->expectsOutputToContain('user@acme.com')
        ->assertSuccessful();
});

it('displays obfuscated token when credentials are configured', function (): void {
    $this->artisan('tix:health')
        ->expectsOutputToContain('********')
        ->assertSuccessful();
});

// ─── 12.2 Credentials not configured ────────────────────────────────────

it('displays not-configured notice and exits SUCCESS when credentials are missing', function (): void {
    $this->config->shouldReceive('getJiraCredentials')->andReturn([
        'base_url' => '',
        'email' => '',
        'token' => '',
    ]);
    $this->config->shouldReceive('isConfigured')->andReturn(false);

    $this->artisan('tix:health')
        ->expectsOutputToContain('not set')
        ->assertSuccessful();
});

// ─── 12.3 Connection success ─────────────────────────────────────────────

it('displays connected display name on successful connection', function (): void {
    $this->jira->shouldReceive('testConnection')->andReturn('Alice Smith');

    $this->artisan('tix:health')
        ->expectsOutputToContain('Alice Smith')
        ->assertSuccessful();
});

// ─── 12.4 Connection failure ─────────────────────────────────────────────

it('displays connection error message when connection fails', function (): void {
    $this->jira->shouldReceive('testConnection')->andThrow(new RuntimeException('Authentication failed'));

    $this->artisan('tix:health')
        ->expectsOutputToContain('Authentication failed')
        ->assertSuccessful();
});

// ─── 12.5 Project linked ─────────────────────────────────────────────────

it('displays project key when project is linked', function (): void {
    $this->project->shouldReceive('hasProjectConfig')->andReturn(true);
    $this->project->shouldReceive('getProjectKey')->andReturn('MYPROJ');
    $this->project->shouldReceive('getProjectConfigPath')->andReturn('/repo/.ticket-context.json');
    $this->project->shouldReceive('getCurrentBranch')->andReturn(null);

    $this->artisan('tix:health')
        ->expectsOutputToContain('MYPROJ')
        ->assertSuccessful();
});

it('displays config file path when project is linked', function (): void {
    $this->project->shouldReceive('hasProjectConfig')->andReturn(true);
    $this->project->shouldReceive('getProjectKey')->andReturn('MYPROJ');
    $this->project->shouldReceive('getProjectConfigPath')->andReturn('/repo/.ticket-context.json');
    $this->project->shouldReceive('getCurrentBranch')->andReturn(null);

    $this->artisan('tix:health')
        ->expectsOutputToContain('/repo/.ticket-context.json')
        ->assertSuccessful();
});

// ─── 12.6 No project linked ──────────────────────────────────────────────

it('displays no project linked notice when no config exists', function (): void {
    $this->project->shouldReceive('hasProjectConfig')->andReturn(false);

    $this->artisan('tix:health')
        ->expectsOutputToContain('no .ticket-context.json found')
        ->assertSuccessful();
});

// ─── 12.7 Branch with linked tickets ─────────────────────────────────────

it('displays current branch name when project is linked', function (): void {
    $this->project->shouldReceive('hasProjectConfig')->andReturn(true);
    $this->project->shouldReceive('getProjectKey')->andReturn('PROJ');
    $this->project->shouldReceive('getProjectConfigPath')->andReturn('/repo/.ticket-context.json');
    $this->project->shouldReceive('getCurrentBranch')->andReturn('feature/proj-42-my-feature');
    $this->project->shouldReceive('getTicketsForBranch')->with('feature/proj-42-my-feature')->andReturn(['PROJ-42']);

    $this->artisan('tix:health')
        ->expectsOutputToContain('feature/proj-42-my-feature')
        ->assertSuccessful();
});

it('displays linked ticket keys for the current branch', function (): void {
    $this->project->shouldReceive('hasProjectConfig')->andReturn(true);
    $this->project->shouldReceive('getProjectKey')->andReturn('PROJ');
    $this->project->shouldReceive('getProjectConfigPath')->andReturn('/repo/.ticket-context.json');
    $this->project->shouldReceive('getCurrentBranch')->andReturn('feature/proj-42-my-feature');
    $this->project->shouldReceive('getTicketsForBranch')->andReturn(['PROJ-42']);

    $this->artisan('tix:health')
        ->expectsOutputToContain('PROJ-42')
        ->assertSuccessful();
});
