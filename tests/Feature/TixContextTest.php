<?php

declare(strict_types=1);

use App\Services\JiraService;
use App\Services\ProjectStore;

use function Pest\Laravel\mock;

beforeEach(function (): void {
    $this->tempDir = (string) realpath(sys_get_temp_dir()).'/tix-context-test-'.uniqid();
    mkdir($this->tempDir, 0755, true);

    $this->jira = mock(JiraService::class);
    $this->project = mock(ProjectStore::class);

    $this->project->shouldReceive('getProjectRoot')->andReturn($this->tempDir)->byDefault();
    $this->project->shouldReceive('getContextDir')->andReturn($this->tempDir)->byDefault();
    $this->project->shouldReceive('getCurrentBranch')->andReturn(null)->byDefault();
    $this->project->shouldReceive('getTicketsForBranch')->andReturn([])->byDefault();
});

afterEach(function (): void {
    // Clean up context files
    foreach (glob($this->tempDir.'/*.md') as $file) {
        unlink($file);
    }
    if (is_dir($this->tempDir)) {
        @rmdir($this->tempDir);
    }
});

/** Build a minimal fake issue array. */
function fakeIssue(string $key, string $summary = 'Test issue', array $overrides = []): array
{
    return array_merge([
        'key' => $key,
        'fields' => [
            'summary' => $summary,
            'status' => ['name' => 'To Do'],
            'issuetype' => ['name' => 'Task'],
            'priority' => ['name' => 'Medium'],
            'assignee' => ['displayName' => 'Alice'],
            'description' => 'Plain description',
            'comment' => ['comments' => []],
        ],
    ], $overrides);
}

// ─── 9.1 Explicit key(s) ─────────────────────────────────────────────────

it('fetches the given key from Jira', function (): void {
    $this->jira->shouldReceive('getIssue')->with('PROJ-1')->andReturn(fakeIssue('PROJ-1'));
    $this->jira->shouldReceive('descriptionAsMarkdown')->andReturn('Plain description');
    $this->jira->shouldReceive('extractComments')->andReturn([]);

    $this->artisan('tix:context', ['keys' => ['PROJ-1']])->assertSuccessful();
});

it('saves context file to getContextDir()/{KEY}-context.md', function (): void {
    $this->jira->shouldReceive('getIssue')->with('PROJ-1')->andReturn(fakeIssue('PROJ-1'));
    $this->jira->shouldReceive('descriptionAsMarkdown')->andReturn('Plain description');
    $this->jira->shouldReceive('extractComments')->andReturn([]);

    $this->artisan('tix:context', ['keys' => ['PROJ-1']])->assertSuccessful();

    expect(file_exists($this->tempDir.'/PROJ-1-context.md'))->toBeTrue();
});

it('context file contains # KEY - Summary header', function (): void {
    $this->jira->shouldReceive('getIssue')->andReturn(fakeIssue('PROJ-1', 'My Summary'));
    $this->jira->shouldReceive('descriptionAsMarkdown')->andReturn('description');
    $this->jira->shouldReceive('extractComments')->andReturn([]);

    $this->artisan('tix:context', ['keys' => ['PROJ-1']])->assertSuccessful();

    $content = file_get_contents($this->tempDir.'/PROJ-1-context.md');
    expect($content)->toContain('# PROJ-1 - My Summary');
});

it('context file contains status, type, priority, assignee metadata line', function (): void {
    $this->jira->shouldReceive('getIssue')->andReturn(fakeIssue('PROJ-1'));
    $this->jira->shouldReceive('descriptionAsMarkdown')->andReturn('desc');
    $this->jira->shouldReceive('extractComments')->andReturn([]);

    $this->artisan('tix:context', ['keys' => ['PROJ-1']])->assertSuccessful();

    $content = file_get_contents($this->tempDir.'/PROJ-1-context.md');
    expect($content)->toContain('**Type:** Task');
    expect($content)->toContain('**Status:** To Do');
    expect($content)->toContain('**Priority:** Medium');
    expect($content)->toContain('**Assignee:** Alice');
});

it('context file contains Description section', function (): void {
    $this->jira->shouldReceive('getIssue')->andReturn(fakeIssue('PROJ-1'));
    $this->jira->shouldReceive('descriptionAsMarkdown')->andReturn('Some description text');
    $this->jira->shouldReceive('extractComments')->andReturn([]);

    $this->artisan('tix:context', ['keys' => ['PROJ-1']])->assertSuccessful();

    expect(file_get_contents($this->tempDir.'/PROJ-1-context.md'))->toContain('## Description');
});

// ─── 9.2 Auto-detection from branch ──────────────────────────────────────

it('auto-detects tickets from current branch when no keys given', function (): void {
    $this->project->shouldReceive('getCurrentBranch')->andReturn('feature/proj-1-thing');
    $this->project->shouldReceive('getTicketsForBranch')->with('feature/proj-1-thing')->andReturn(['PROJ-1']);
    $this->jira->shouldReceive('getIssue')->with('PROJ-1')->andReturn(fakeIssue('PROJ-1'));
    $this->jira->shouldReceive('descriptionAsMarkdown')->andReturn('desc');
    $this->jira->shouldReceive('extractComments')->andReturn([]);

    $this->artisan('tix:context')
        ->expectsOutputToContain('Auto-detected tickets from branch')
        ->assertSuccessful();
});

// ─── 9.3 No keys, no branch tickets ──────────────────────────────────────

it('displays error and exits FAILURE when no keys and no branch tickets', function (): void {
    $this->project->shouldReceive('getCurrentBranch')->andReturn(null);

    $this->artisan('tix:context')
        ->expectsOutputToContain('No ticket keys provided')
        ->assertFailed();
});

// ─── 9.4 Multi-ticket context ────────────────────────────────────────────

it('multiple keys produce a single file with sections separated by ---', function (): void {
    $this->jira->shouldReceive('getIssue')->with('PROJ-1')->andReturn(fakeIssue('PROJ-1', 'First'));
    $this->jira->shouldReceive('getIssue')->with('PROJ-2')->andReturn(fakeIssue('PROJ-2', 'Second'));
    $this->jira->shouldReceive('descriptionAsMarkdown')->andReturn('desc');
    $this->jira->shouldReceive('extractComments')->andReturn([]);

    $this->artisan('tix:context', ['keys' => ['PROJ-1', 'PROJ-2']])->assertSuccessful();

    $content = file_get_contents($this->tempDir.'/PROJ-1-context.md');
    expect($content)->toContain('# PROJ-1 - First');
    expect($content)->toContain('# PROJ-2 - Second');
    expect($content)->toContain("\n\n---\n\n");
});

// ─── 9.5 Comments in context ─────────────────────────────────────────────

it('context file includes Comments section with author and date headings', function (): void {
    $this->jira->shouldReceive('getIssue')->andReturn(fakeIssue('PROJ-1'));
    $this->jira->shouldReceive('descriptionAsMarkdown')->andReturn('desc');
    $this->jira->shouldReceive('extractComments')->andReturn([
        ['author' => 'Bob', 'date' => '2024-05-01', 'body' => 'Looks good'],
    ]);

    $this->artisan('tix:context', ['keys' => ['PROJ-1']])->assertSuccessful();

    $content = file_get_contents($this->tempDir.'/PROJ-1-context.md');
    expect($content)->toContain('## Comments');
    expect($content)->toContain('### Bob — 2024-05-01');
    expect($content)->toContain('Looks good');
});

// ─── 9.6 No comments ─────────────────────────────────────────────────────

it('omits Comments section when there are no comments', function (): void {
    $this->jira->shouldReceive('getIssue')->andReturn(fakeIssue('PROJ-1'));
    $this->jira->shouldReceive('descriptionAsMarkdown')->andReturn('desc');
    $this->jira->shouldReceive('extractComments')->andReturn([]);

    $this->artisan('tix:context', ['keys' => ['PROJ-1']])->assertSuccessful();

    expect(file_get_contents($this->tempDir.'/PROJ-1-context.md'))->not->toContain('## Comments');
});

// ─── 9.7 --transition flag ───────────────────────────────────────────────

it('calls transitionIssue for each key when --transition flag provided', function (): void {
    $this->jira->shouldReceive('getIssue')->andReturn(fakeIssue('PROJ-1', 'T', ['fields' => ['status' => ['name' => 'To Do'], 'summary' => 'T', 'issuetype' => ['name' => 'Task'], 'priority' => ['name' => 'Low'], 'assignee' => null, 'description' => null, 'comment' => ['comments' => []]]]));
    $this->jira->shouldReceive('descriptionAsMarkdown')->andReturn('desc');
    $this->jira->shouldReceive('extractComments')->andReturn([]);
    $this->jira->shouldReceive('transitionIssue')->with('PROJ-1', 'In Progress')->andReturn(true)->once();

    $this->artisan('tix:context', ['keys' => ['PROJ-1'], '--transition' => 'In Progress'])->assertSuccessful();
});

it('transition failure is a warning and context is still saved', function (): void {
    $this->jira->shouldReceive('getIssue')->andReturn(fakeIssue('PROJ-1'));
    $this->jira->shouldReceive('descriptionAsMarkdown')->andReturn('desc');
    $this->jira->shouldReceive('extractComments')->andReturn([]);
    $this->jira->shouldReceive('transitionIssue')->andReturn(false);

    $this->artisan('tix:context', ['keys' => ['PROJ-1'], '--transition' => 'Blocked'])->assertSuccessful();

    expect(file_exists($this->tempDir.'/PROJ-1-context.md'))->toBeTrue();
});

// ─── 9.8 API failure ─────────────────────────────────────────────────────

it('displays error and exits FAILURE when API call fails', function (): void {
    $this->jira->shouldReceive('getIssue')->andThrow(new RuntimeException('Jira error'));

    $this->artisan('tix:context', ['keys' => ['PROJ-1']])
        ->expectsOutputToContain('Could not fetch PROJ-1')
        ->assertFailed();
});

// ─── 9.9 buildMarkdown() unit cases ──────────────────────────────────────

it('null assignee renders as Unassigned in context file', function (): void {
    $issue = fakeIssue('PROJ-1');
    $issue['fields']['assignee'] = null;
    $this->jira->shouldReceive('getIssue')->andReturn($issue);
    $this->jira->shouldReceive('descriptionAsMarkdown')->andReturn('desc');
    $this->jira->shouldReceive('extractComments')->andReturn([]);

    $this->artisan('tix:context', ['keys' => ['PROJ-1']])->assertSuccessful();

    expect(file_get_contents($this->tempDir.'/PROJ-1-context.md'))->toContain('Unassigned');
});

it('null priority renders as Unknown in context file', function (): void {
    $issue = fakeIssue('PROJ-1');
    $issue['fields']['priority'] = null;
    $this->jira->shouldReceive('getIssue')->andReturn($issue);
    $this->jira->shouldReceive('descriptionAsMarkdown')->andReturn('desc');
    $this->jira->shouldReceive('extractComments')->andReturn([]);

    $this->artisan('tix:context', ['keys' => ['PROJ-1']])->assertSuccessful();

    expect(file_get_contents($this->tempDir.'/PROJ-1-context.md'))->toContain('Unknown');
});
