<?php

declare(strict_types=1);

use App\Services\JiraService;
use App\Services\ProjectStore;

use function Pest\Laravel\mock;

beforeEach(function (): void {
    $this->jira = mock(JiraService::class);
    $this->project = mock(ProjectStore::class);

    $this->project->shouldReceive('getProjectRoot')->andReturn(null)->byDefault();
    $this->project->shouldReceive('getContextDir')->andReturn(sys_get_temp_dir())->byDefault();
    $this->project->shouldReceive('getCurrentBranch')->andReturn(null)->byDefault();
    $this->project->shouldReceive('getProjectKey')->andReturn('PROJ')->byDefault();
});

// ─── 7.1 With linked project ─────────────────────────────────────────────

it('calls getProjectStatuses with the linked project key', function (): void {
    $this->project->shouldReceive('getProjectKey')->andReturn('MYPROJ');
    $this->jira->shouldReceive('getProjectStatuses')->with('MYPROJ')->andReturn(['Done', 'In Progress']);

    $this->artisan('tix:statuses')->assertSuccessful();
});

it('renders statuses in a single-column table', function (): void {
    $this->jira->shouldReceive('getProjectStatuses')->andReturn(['Done', 'In Progress', 'To Do']);

    $this->artisan('tix:statuses')->expectsOutputToContain('Status')->assertSuccessful();
});

// ─── 7.2 With explicit project argument ──────────────────────────────────

it('uses provided project key argument instead of linked one', function (): void {
    $this->project->shouldReceive('getProjectKey')->andReturn('LINKED');
    $this->jira->shouldReceive('getProjectStatuses')->with('EXPLICIT')->andReturn(['Done']);

    $this->artisan('tix:statuses', ['project' => 'explicit'])->assertSuccessful();
});

// ─── 7.3 No project available ────────────────────────────────────────────

it('displays error and exits FAILURE when no project linked and no argument given', function (): void {
    $this->project->shouldReceive('getProjectKey')->andReturn(null);

    $this->artisan('tix:statuses')
        ->expectsOutputToContain('No project key provided')
        ->assertFailed();
});

// ─── 7.4 Empty statuses ──────────────────────────────────────────────────

it('displays warning and exits SUCCESS when no statuses returned', function (): void {
    $this->jira->shouldReceive('getProjectStatuses')->andReturn([]);

    $this->artisan('tix:statuses')
        ->expectsOutputToContain('No statuses found')
        ->assertSuccessful();
});

// ─── 7.5 API failure ─────────────────────────────────────────────────────

it('displays error and exits FAILURE on API exception', function (): void {
    $this->jira->shouldReceive('getProjectStatuses')->andThrow(new RuntimeException('Project not found'));

    $this->artisan('tix:statuses')
        ->expectsOutputToContain('Project not found')
        ->assertFailed();
});
