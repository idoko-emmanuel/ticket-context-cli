<?php

declare(strict_types=1);

use App\Services\ProjectStore;

use function Pest\Laravel\mock;

beforeEach(function (): void {
    $this->tempDir = (string) realpath(sys_get_temp_dir()).'/tix-command-test-'.uniqid();
    mkdir($this->tempDir, 0755, true);

    $this->project = mock(ProjectStore::class);
    $this->project->shouldReceive('getProjectRoot')->andReturn($this->tempDir)->byDefault();
    $this->project->shouldReceive('getContextDir')->andReturn($this->tempDir)->byDefault();
    $this->project->shouldReceive('getCurrentBranch')->andReturn(null)->byDefault();
});

afterEach(function (): void {
    foreach (glob($this->tempDir.'/*') ?? [] as $file) {
        unlink($file);
    }
    @rmdir($this->tempDir);
});

// ─── 14.1 SKILL.md created on first run ──────────────────────────────────

it('creates SKILL.md in the context dir on first run', function (): void {
    $skillFile = $this->tempDir.'/SKILL.md';
    expect(file_exists($skillFile))->toBeFalse();

    $this->artisan('tix:help')->assertSuccessful();

    expect(file_exists($skillFile))->toBeTrue();
});

it('SKILL.md contains the name frontmatter', function (): void {
    $this->artisan('tix:help')->assertSuccessful();

    $content = file_get_contents($this->tempDir.'/SKILL.md');
    expect($content)->toContain('name: ticket-context');
});

it('SKILL.md contains the commands reference block', function (): void {
    $this->artisan('tix:help')->assertSuccessful();

    $content = file_get_contents($this->tempDir.'/SKILL.md');
    expect($content)->toContain('tix branch');
});

// ─── 14.2 Idempotent — SKILL.md not overwritten ──────────────────────────

it('does not overwrite an existing SKILL.md', function (): void {
    $skillFile = $this->tempDir.'/SKILL.md';
    file_put_contents($skillFile, 'custom content');

    $this->artisan('tix:help')->assertSuccessful();

    expect(file_get_contents($skillFile))->toBe('custom content');
});

// ─── 14.3 No project root — silently skips ───────────────────────────────

it('does not create any files when no project root is set', function (): void {
    $this->project->shouldReceive('getProjectRoot')->andReturn(null);

    $this->artisan('tix:help')->assertSuccessful();

    expect(glob($this->tempDir.'/*'))->toBeEmpty();
});
