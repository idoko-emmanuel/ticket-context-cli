<?php

declare(strict_types=1);

use App\Services\JiraService;
use App\Services\ProjectStore;

use function Pest\Laravel\mock;

beforeEach(function (): void {
    $this->jira = mock(JiraService::class);
    $this->project = mock(ProjectStore::class);

    // Default: no project linked, no branch
    $this->project->shouldReceive('getProjectKey')->andReturn(null)->byDefault();
    $this->project->shouldReceive('getProjectRoot')->andReturn(null)->byDefault();
    $this->project->shouldReceive('getContextDir')->andReturn(sys_get_temp_dir())->byDefault();
    $this->project->shouldReceive('getCurrentBranch')->andReturn(null)->byDefault();
});

// ─── 6.1 Table output ────────────────────────────────────────────────────

it('renders a table with Key column header', function (): void {
    $this->jira->shouldReceive('getActiveSprintIssues')->andReturn([[
        'key' => 'PROJ-1',
        'fields' => [
            'summary' => 'Fix the bug',
            'status' => ['name' => 'In Progress'],
            'issuetype' => ['name' => 'Bug'],
            'priority' => ['name' => 'High'],
        ],
    ]]);

    // Column headers and data rows are separate doWrite calls, so each can be asserted independently.
    // Only one expectsOutputToContain per artisan() call to avoid Mockery single-match limitation.
    $this->artisan('tix:sprint')->expectsOutputToContain('Key')->assertSuccessful();
});

it('renders a table with Summary column header', function (): void {
    $this->jira->shouldReceive('getActiveSprintIssues')->andReturn([[
        'key' => 'PROJ-1',
        'fields' => ['summary' => 'Fix the bug', 'status' => ['name' => 'In Progress'], 'issuetype' => ['name' => 'Bug'], 'priority' => ['name' => 'High']],
    ]]);

    $this->artisan('tix:sprint')->expectsOutputToContain('Summary')->assertSuccessful();
});

it('renders the ticket key in the table', function (): void {
    $this->jira->shouldReceive('getActiveSprintIssues')->andReturn([[
        'key' => 'PROJ-99',
        'fields' => ['summary' => 'A task', 'status' => ['name' => 'To Do'], 'issuetype' => ['name' => 'Task'], 'priority' => ['name' => 'Low']],
    ]]);

    $this->artisan('tix:sprint')->expectsOutputToContain('PROJ-99')->assertSuccessful();
});

it('truncates summary longer than 60 chars to 57 chars + ...', function (): void {
    $longSummary = str_repeat('A', 65);

    $this->jira->shouldReceive('getActiveSprintIssues')->andReturn([[
        'key' => 'PROJ-1',
        'fields' => [
            'summary' => $longSummary,
            'status' => ['name' => 'To Do'],
            'issuetype' => ['name' => 'Task'],
            'priority' => ['name' => 'Medium'],
        ],
    ]]);

    $this->artisan('tix:sprint')
        ->expectsOutputToContain(str_repeat('A', 57).'...')
        ->assertSuccessful();
});

it('displays ticket count at the bottom', function (): void {
    $this->jira->shouldReceive('getActiveSprintIssues')->andReturn([
        ['key' => 'PROJ-1', 'fields' => ['summary' => 'One', 'status' => ['name' => 'To Do'], 'issuetype' => ['name' => 'Task'], 'priority' => ['name' => 'Low']]],
        ['key' => 'PROJ-2', 'fields' => ['summary' => 'Two', 'status' => ['name' => 'To Do'], 'issuetype' => ['name' => 'Task'], 'priority' => ['name' => 'Low']]],
    ]);

    $this->artisan('tix:sprint')
        ->expectsOutputToContain('2 ticket(s) found')
        ->assertSuccessful();
});

// ─── 6.2 Project scoping ─────────────────────────────────────────────────

it('without --all passes linked project key to getActiveSprintIssues', function (): void {
    $this->project->shouldReceive('getProjectKey')->andReturn('MYPROJ');
    $this->jira->shouldReceive('getActiveSprintIssues')->with('MYPROJ')->andReturn([]);

    $this->artisan('tix:sprint')->assertSuccessful();
});

it('with --all passes null to getActiveSprintIssues', function (): void {
    $this->project->shouldReceive('getProjectKey')->andReturn('MYPROJ');
    $this->jira->shouldReceive('getActiveSprintIssues')->with(null)->andReturn([]);

    $this->artisan('tix:sprint', ['--all' => true])->assertSuccessful();
});

it('when no project is linked behaves like --all', function (): void {
    $this->project->shouldReceive('getProjectKey')->andReturn(null);
    $this->jira->shouldReceive('getActiveSprintIssues')->with(null)->andReturn([]);

    $this->artisan('tix:sprint')->assertSuccessful();
});

// ─── 6.3 JSON output ─────────────────────────────────────────────────────

it('--json flag outputs raw JSON instead of a table', function (): void {
    $this->jira->shouldReceive('getActiveSprintIssues')->andReturn([['key' => 'PROJ-1']]);

    $this->artisan('tix:sprint', ['--json' => true])
        ->expectsOutputToContain('"PROJ-1"')
        ->assertSuccessful();
});

// ─── 6.4 Empty results ───────────────────────────────────────────────────

it('displays no-tickets message and exits successfully when sprint is empty', function (): void {
    $this->jira->shouldReceive('getActiveSprintIssues')->andReturn([]);

    $this->artisan('tix:sprint')
        ->expectsOutputToContain('No tickets found in the active sprint.')
        ->assertSuccessful();
});

// ─── 6.5 API failure ─────────────────────────────────────────────────────

it('displays error and exits with failure on API exception', function (): void {
    $this->jira->shouldReceive('getActiveSprintIssues')->andThrow(new RuntimeException('API is down'));

    $this->artisan('tix:sprint')
        ->expectsOutputToContain('API is down')
        ->assertFailed();
});
