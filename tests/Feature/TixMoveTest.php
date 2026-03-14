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
    $this->project->shouldReceive('getTicketsForBranch')->andReturn([])->byDefault();
});

// ─── 8.1 Argument parsing ────────────────────────────────────────────────

it('parses single key and status from args', function (): void {
    $this->jira->shouldReceive('transitionIssue')->with('LTN-123', 'In Progress')->andReturn(true);

    $this->artisan('tix:move', ['args' => ['LTN-123', 'In', 'Progress']])
        ->expectsOutputToContain('LTN-123 moved to In Progress')
        ->assertSuccessful();
});

it('parses multiple keys and status from args', function (): void {
    $this->jira->shouldReceive('transitionIssue')->with('LTN-123', 'Done')->andReturn(true);
    $this->jira->shouldReceive('transitionIssue')->with('LTN-124', 'Done')->andReturn(true);

    $this->artisan('tix:move', ['args' => ['LTN-123', 'LTN-124', 'Done']])
        ->assertSuccessful();
});

it('exits FAILURE when only status words provided with no keys and no branch tickets', function (): void {
    $this->project->shouldReceive('getCurrentBranch')->andReturn(null);

    $this->artisan('tix:move', ['args' => ['In', 'Review']])
        ->expectsOutputToContain('No ticket keys provided')
        ->assertFailed();
});

it('exits FAILURE when no status words provided', function (): void {
    $this->artisan('tix:move', ['args' => ['LTN-123']])
        ->expectsOutputToContain('No status provided')
        ->assertFailed();
});

// ─── 8.2 Auto-detection ──────────────────────────────────────────────────

it('uses tickets from current branch when no keys given in args', function (): void {
    $this->project->shouldReceive('getCurrentBranch')->andReturn('feature/ltn-5-thing');
    $this->project->shouldReceive('getTicketsForBranch')->with('feature/ltn-5-thing')->andReturn(['LTN-5']);
    $this->jira->shouldReceive('transitionIssue')->with('LTN-5', 'Done')->andReturn(true);

    $this->artisan('tix:move', ['args' => ['Done']])->assertSuccessful();
});

it('displays error and exits FAILURE when no keys and no branch tickets', function (): void {
    $this->project->shouldReceive('getCurrentBranch')->andReturn('feature/no-tickets');
    $this->project->shouldReceive('getTicketsForBranch')->andReturn([]);

    $this->artisan('tix:move', ['args' => ['Done']])
        ->expectsOutputToContain('No ticket keys provided')
        ->assertFailed();
});

// ─── 8.3 Successful transition ───────────────────────────────────────────

it('calls transitionIssue for each key and displays success message', function (): void {
    $this->jira->shouldReceive('transitionIssue')->with('LTN-1', 'Done')->andReturn(true);

    $this->artisan('tix:move', ['args' => ['LTN-1', 'Done']])
        ->expectsOutputToContain('LTN-1 moved to Done')
        ->assertSuccessful();
});

// ─── 8.4 Transition unavailable ──────────────────────────────────────────

it('displays warning with tix statuses hint when transition is not available', function (): void {
    $this->jira->shouldReceive('transitionIssue')->andReturn(false);

    $this->artisan('tix:move', ['args' => ['LTN-1', 'Blocked']])
        ->expectsOutputToContain('tix statuses')
        ->assertFailed();
});

// ─── 8.5 Partial failure ─────────────────────────────────────────────────

it('continues processing all tickets even when one throws and exits FAILURE', function (): void {
    $this->jira->shouldReceive('transitionIssue')->with('LTN-1', 'Done')->andReturn(true);
    $this->jira->shouldReceive('transitionIssue')->with('LTN-2', 'Done')->andThrow(new RuntimeException('API error'));

    $this->artisan('tix:move', ['args' => ['LTN-1', 'LTN-2', 'Done']])
        ->expectsOutputToContain('LTN-1 moved to Done')
        ->assertFailed();
});

// ─── 8.6 API error ───────────────────────────────────────────────────────

it('shows error message and exits FAILURE on API exception', function (): void {
    $this->jira->shouldReceive('transitionIssue')->andThrow(new RuntimeException('Jira is down'));

    $this->artisan('tix:move', ['args' => ['LTN-1', 'Done']])
        ->expectsOutputToContain('Jira is down')
        ->assertFailed();
});
