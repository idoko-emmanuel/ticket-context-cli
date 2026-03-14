<?php

declare(strict_types=1);

use App\Services\ProjectStore;

use function Pest\Laravel\mock;

beforeEach(function (): void {
    $this->tempDir = (string) realpath(sys_get_temp_dir()).'/tix-link-test-'.uniqid();
    mkdir($this->tempDir, 0755, true);
    $this->originalCwd = (string) getcwd();
    chdir($this->tempDir);

    $this->project = mock(ProjectStore::class);
    $this->project->shouldReceive('getProjectRoot')->andReturn(null)->byDefault();
    $this->project->shouldReceive('getContextDir')->andReturn($this->tempDir)->byDefault();
    $this->project->shouldReceive('getCurrentBranch')->andReturn(null)->byDefault();
    $this->project->shouldReceive('hasProjectConfig')->andReturn(false)->byDefault();
});

afterEach(function (): void {
    chdir($this->originalCwd);

    foreach (glob($this->tempDir.'/*') ?? [] as $file) {
        unlink($file);
    }
    @rmdir($this->tempDir);
});

// ─── 11.1 New project link ────────────────────────────────────────────────

it('creates .ticket-context.json and adds gitignore entry on first link', function (): void {
    $this->project->shouldReceive('hasProjectConfig')->andReturn(false);
    $this->project->shouldReceive('linkProject')->with('PROJ')->once();
    $this->project->shouldReceive('getProjectConfigPath')->andReturn($this->tempDir.'/.ticket-context.json');

    $this->artisan('tix:link', ['project' => 'PROJ'])
        ->expectsOutputToContain('Linked')
        ->assertSuccessful();
});

it('adds gitignore entry for context md files', function (): void {
    $this->project->shouldReceive('hasProjectConfig')->andReturn(false);
    $this->project->shouldReceive('linkProject')->andReturn(null);
    $this->project->shouldReceive('getProjectConfigPath')->andReturn($this->tempDir.'/.ticket-context.json');

    $this->artisan('tix:link', ['project' => 'PROJ'])->assertSuccessful();

    $gitignore = file_get_contents($this->tempDir.'/.gitignore');
    expect($gitignore)->toContain('*-context.md');
});

// ─── 11.2 Already linked to same project ─────────────────────────────────

it('displays "Already linked" and exits SUCCESS without modifying files when same project', function (): void {
    $this->project->shouldReceive('hasProjectConfig')->andReturn(true);
    $this->project->shouldReceive('getProjectKey')->andReturn('PROJ');
    $this->project->shouldReceive('getProjectConfigPath')->andReturn($this->tempDir.'/.ticket-context.json');
    $this->project->shouldReceive('linkProject')->never();

    $this->artisan('tix:link', ['project' => 'PROJ'])
        ->expectsOutputToContain('Already linked to PROJ')
        ->assertSuccessful();
});

// ─── 11.3 Re-link with confirmation accepted ──────────────────────────────

it('overwrites project key when re-link is confirmed', function (): void {
    $this->project->shouldReceive('hasProjectConfig')->andReturn(true);
    $this->project->shouldReceive('getProjectKey')->andReturn('OLD');
    $this->project->shouldReceive('getProjectConfigPath')->andReturn($this->tempDir.'/.ticket-context.json');
    $this->project->shouldReceive('linkProject')->with('NEW')->once();

    $this->artisan('tix:link', ['project' => 'NEW'])
        ->expectsConfirmation('This directory is already linked to OLD. Re-link to NEW?', 'yes')
        ->assertSuccessful();
});

// ─── 11.4 Re-link with confirmation rejected ──────────────────────────────

it('does not re-link when confirmation is rejected', function (): void {
    $this->project->shouldReceive('hasProjectConfig')->andReturn(true);
    $this->project->shouldReceive('getProjectKey')->andReturn('OLD');
    $this->project->shouldReceive('getProjectConfigPath')->andReturn($this->tempDir.'/.ticket-context.json');
    $this->project->shouldReceive('linkProject')->never();

    $this->artisan('tix:link', ['project' => 'NEW'])
        ->expectsConfirmation('This directory is already linked to OLD. Re-link to NEW?', 'no')
        ->assertSuccessful();
});

// ─── 11.5 .gitignore does not exist ──────────────────────────────────────

it('creates new .gitignore with the entry when none exists', function (): void {
    $this->project->shouldReceive('hasProjectConfig')->andReturn(false);
    $this->project->shouldReceive('linkProject')->andReturn(null);
    $this->project->shouldReceive('getProjectConfigPath')->andReturn($this->tempDir.'/.ticket-context.json');

    expect(file_exists($this->tempDir.'/.gitignore'))->toBeFalse();

    $this->artisan('tix:link', ['project' => 'PROJ'])->assertSuccessful();

    expect(file_exists($this->tempDir.'/.gitignore'))->toBeTrue();
    expect(file_get_contents($this->tempDir.'/.gitignore'))->toContain('*-context.md');
});

// ─── 11.6 .gitignore already has the entry ────────────────────────────────

it('does not add duplicate gitignore entry when entry already exists', function (): void {
    $entry = ProjectStore::CONTEXT_DIR.'/*-context.md';
    file_put_contents($this->tempDir.'/.gitignore', "# existing\n{$entry}\n");

    $this->project->shouldReceive('hasProjectConfig')->andReturn(false);
    $this->project->shouldReceive('linkProject')->andReturn(null);
    $this->project->shouldReceive('getProjectConfigPath')->andReturn($this->tempDir.'/.ticket-context.json');

    $this->artisan('tix:link', ['project' => 'PROJ'])->assertSuccessful();

    $content = file_get_contents($this->tempDir.'/.gitignore');
    expect(substr_count($content, $entry))->toBe(1);
});
