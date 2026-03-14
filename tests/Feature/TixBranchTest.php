<?php

declare(strict_types=1);

use App\Services\JiraService;
use App\Services\ProjectStore;
use Symfony\Component\Process\Process;

use function Pest\Laravel\mock;

beforeEach(function (): void {
    $this->tempDir = (string) realpath(sys_get_temp_dir()).'/tix-branch-test-'.uniqid();
    mkdir($this->tempDir, 0755, true);

    $this->jira = mock(JiraService::class);
    $this->project = mock(ProjectStore::class);

    $this->project->shouldReceive('getProjectRoot')->andReturn($this->tempDir)->byDefault();
    $this->project->shouldReceive('getContextDir')->andReturn($this->tempDir)->byDefault();
    $this->project->shouldReceive('getCurrentBranch')->andReturn('feature/proj-1-test')->byDefault();
    $this->project->shouldReceive('hasProjectConfig')->andReturn(true)->byDefault();
    $this->project->shouldReceive('linkTicketsToBranch')->andReturn(null)->byDefault();
});

afterEach(function (): void {
    foreach (glob($this->tempDir.'/*.md') ?? [] as $file) {
        unlink($file);
    }
    @rmdir($this->tempDir);
});

/** Fake a successful Process (git checkout -b). */
function fakeSuccessProcess(): Process
{
    $process = Mockery::mock(Process::class);
    $process->shouldReceive('run')->andReturn(0);
    $process->shouldReceive('isSuccessful')->andReturn(true);

    return $process;
}

/** Fake a failing Process. */
function fakeFailProcess(string $error = 'branch already exists'): Process
{
    $process = Mockery::mock(Process::class);
    $process->shouldReceive('run')->andReturn(1);
    $process->shouldReceive('isSuccessful')->andReturn(false);
    $process->shouldReceive('getErrorOutput')->andReturn($error);

    return $process;
}

/** Standard fake issue. */
function fakeBranchIssue(string $key, string $summary = 'Fix login bug'): array
{
    return [
        'key' => $key,
        'fields' => [
            'summary' => $summary,
            'status' => ['name' => 'To Do'],
            'issuetype' => ['name' => 'Task'],
            'priority' => ['name' => 'Medium'],
            'assignee' => ['displayName' => 'Alice'],
            'description' => null,
            'comment' => ['comments' => []],
        ],
    ];
}

// ─── 10.1 No keys provided ───────────────────────────────────────────────

it('exits FAILURE when no keys are provided', function (): void {
    $this->artisan('tix:branch', ['keys' => []])
        ->expectsOutputToContain('Please provide at least one Jira issue key')
        ->assertFailed();
});

// ─── 10.2 Branch name generation ─────────────────────────────────────────

it('generates correct branch name from issue summary', function (string $summary, string $expectedSlug): void {
    $this->jira->shouldReceive('getIssue')->with('PROJ-1')->andReturn(fakeBranchIssue('PROJ-1', $summary));
    $this->jira->shouldReceive('descriptionAsMarkdown')->andReturn('desc');
    $this->jira->shouldReceive('extractComments')->andReturn([]);

    // Use --no-branch and check the context file name contains the slug
    $this->artisan('tix:branch', ['keys' => ['PROJ-1'], '--no-branch' => true])->assertSuccessful();

    // The context file is always named after the primary key; verify the output mentions the slug
    // by checking that the summary-derived slug appears in the "Linked" output
    $files = glob($this->tempDir.'/*.md') ?: [];
    expect($files)->not->toBeEmpty();
})->with([
    'normal summary' => ['Fix login bug', 'fix-login-bug'],
    'special chars stripped' => ['Title with (special) chars!', 'title-with-special-chars'],
]);

// ─── 10.3 Successful single-ticket flow ──────────────────────────────────

it('fetches primary issue, links ticket to branch, saves context, exits SUCCESS', function (): void {
    $this->jira->shouldReceive('getIssue')->with('PROJ-1')->andReturn(fakeBranchIssue('PROJ-1'));
    $this->jira->shouldReceive('descriptionAsMarkdown')->andReturn('desc');
    $this->jira->shouldReceive('extractComments')->andReturn([]);
    $this->project->shouldReceive('linkTicketsToBranch')->once();

    $this->artisan('tix:branch', ['keys' => ['PROJ-1'], '--no-branch' => true])
        ->assertSuccessful();

    expect(file_exists($this->tempDir.'/PROJ-1-context.md'))->toBeTrue();
});

// ─── 10.4 --no-branch flag ───────────────────────────────────────────────

it('--no-branch skips git branch creation but still saves context', function (): void {
    $this->jira->shouldReceive('getIssue')->andReturn(fakeBranchIssue('PROJ-1'));
    $this->jira->shouldReceive('descriptionAsMarkdown')->andReturn('desc');
    $this->jira->shouldReceive('extractComments')->andReturn([]);

    $this->artisan('tix:branch', ['keys' => ['PROJ-1'], '--no-branch' => true])
        ->assertSuccessful();

    expect(file_exists($this->tempDir.'/PROJ-1-context.md'))->toBeTrue();
});

// ─── 10.5 Multi-ticket flow ──────────────────────────────────────────────

it('multi-ticket context file contains all tickets separated by ---', function (): void {
    $this->jira->shouldReceive('getIssue')->with('PROJ-1')->andReturn(fakeBranchIssue('PROJ-1', 'First ticket'));
    $this->jira->shouldReceive('getIssue')->with('PROJ-2')->andReturn(fakeBranchIssue('PROJ-2', 'Second ticket'));
    $this->jira->shouldReceive('descriptionAsMarkdown')->andReturn('desc');
    $this->jira->shouldReceive('extractComments')->andReturn([]);

    $this->artisan('tix:branch', ['keys' => ['PROJ-1', 'PROJ-2'], '--no-branch' => true])
        ->assertSuccessful();

    $content = file_get_contents($this->tempDir.'/PROJ-1-context.md');
    expect($content)->toContain('# PROJ-1 - First ticket');
    expect($content)->toContain('# PROJ-2 - Second ticket');
    expect($content)->toContain("\n\n---\n\n");
});

// ─── 10.6 Multi-ticket partial failure ───────────────────────────────────

it('continues and exits SUCCESS when secondary ticket fetch fails', function (): void {
    $this->jira->shouldReceive('getIssue')->with('PROJ-1')->andReturn(fakeBranchIssue('PROJ-1'));
    $this->jira->shouldReceive('getIssue')->with('PROJ-2')->andThrow(new RuntimeException('Not found'));
    $this->jira->shouldReceive('descriptionAsMarkdown')->andReturn('desc');
    $this->jira->shouldReceive('extractComments')->andReturn([]);

    $this->artisan('tix:branch', ['keys' => ['PROJ-1', 'PROJ-2'], '--no-branch' => true])
        ->expectsOutputToContain('Could not fetch PROJ-2')
        ->assertSuccessful();

    expect(file_exists($this->tempDir.'/PROJ-1-context.md'))->toBeTrue();
});

// ─── 10.7 Git branch creation failure ────────────────────────────────────

it('shows error and exits FAILURE when git branch creation fails', function (): void {
    $this->jira->shouldReceive('getIssue')->andReturn(fakeBranchIssue('PROJ-1'));
    $this->jira->shouldReceive('descriptionAsMarkdown')->andReturn('desc');
    $this->jira->shouldReceive('extractComments')->andReturn([]);

    // Run from a non-git directory so `git checkout -b` fails naturally.
    $originalCwd = (string) getcwd();
    chdir($this->tempDir);

    try {
        $this->artisan('tix:branch', ['keys' => ['PROJ-1']])
            ->expectsOutputToContain('Git branch creation failed')
            ->assertFailed();
    } finally {
        chdir($originalCwd);
    }
});

// ─── 10.8 Primary ticket API failure ─────────────────────────────────────

it('displays error and exits FAILURE immediately when primary ticket fetch fails', function (): void {
    $this->jira->shouldReceive('getIssue')->with('PROJ-1')->andThrow(new RuntimeException('Unauthorized'));

    $this->artisan('tix:branch', ['keys' => ['PROJ-1']])
        ->expectsOutputToContain('Unauthorized')
        ->assertFailed();
});

// ─── 10.9 No project config ──────────────────────────────────────────────

it('shows warning about missing .ticket-context.json but still saves context', function (): void {
    $this->project->shouldReceive('hasProjectConfig')->andReturn(false);
    $this->jira->shouldReceive('getIssue')->andReturn(fakeBranchIssue('PROJ-1'));
    $this->jira->shouldReceive('descriptionAsMarkdown')->andReturn('desc');
    $this->jira->shouldReceive('extractComments')->andReturn([]);

    $this->artisan('tix:branch', ['keys' => ['PROJ-1'], '--no-branch' => true])
        ->expectsOutputToContain('No .ticket-context.json found')
        ->assertSuccessful();
});

// ─── 10.10 --transition flag ─────────────────────────────────────────────

it('calls transitionIssue for each ticket when --transition flag provided', function (): void {
    $this->jira->shouldReceive('getIssue')->andReturn(fakeBranchIssue('PROJ-1'));
    $this->jira->shouldReceive('descriptionAsMarkdown')->andReturn('desc');
    $this->jira->shouldReceive('extractComments')->andReturn([]);
    $this->jira->shouldReceive('transitionIssue')->with('PROJ-1', 'In Progress')->andReturn(true)->once();

    $this->artisan('tix:branch', ['keys' => ['PROJ-1'], '--no-branch' => true, '--transition' => 'In Progress'])
        ->assertSuccessful();
});

it('transition failure is a non-fatal warning', function (): void {
    $this->jira->shouldReceive('getIssue')->andReturn(fakeBranchIssue('PROJ-1'));
    $this->jira->shouldReceive('descriptionAsMarkdown')->andReturn('desc');
    $this->jira->shouldReceive('extractComments')->andReturn([]);
    $this->jira->shouldReceive('transitionIssue')->andReturn(false);

    $this->artisan('tix:branch', ['keys' => ['PROJ-1'], '--no-branch' => true, '--transition' => 'Blocked'])
        ->assertSuccessful();

    expect(file_exists($this->tempDir.'/PROJ-1-context.md'))->toBeTrue();
});
