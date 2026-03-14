<?php

declare(strict_types=1);

use App\Services\ProjectStore;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    $base = sys_get_temp_dir().'/project-store-test-'.uniqid();
    mkdir($base, 0755, true);
    $this->tempDir = (string) realpath($base); // resolve symlinks (macOS /var → /private/var)
    $this->originalCwd = (string) getcwd();
    chdir($this->tempDir);
});

afterEach(function (): void {
    chdir($this->originalCwd);

    // Remove temp tree recursively
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($this->tempDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($this->tempDir);
});

// ─── 3.1 Config discovery ────────────────────────────────────────────────

it('hasProjectConfig returns false when no .ticket-context.json exists', function (): void {
    $store = new ProjectStore;

    expect($store->hasProjectConfig())->toBeFalse();
});

it('discovers .ticket-context.json in the current directory', function (): void {
    file_put_contents($this->tempDir.'/.ticket-context.json', json_encode(['project' => 'PROJ', 'branches' => []]));

    $store = new ProjectStore;

    expect($store->hasProjectConfig())->toBeTrue();
    expect($store->getProjectKey())->toBe('PROJ');
});

it('discovers .ticket-context.json two levels up', function (): void {
    file_put_contents($this->tempDir.'/.ticket-context.json', json_encode(['project' => 'UP', 'branches' => []]));

    $subDir = $this->tempDir.'/a/b';
    mkdir($subDir, 0755, true);
    chdir($subDir);

    $store = new ProjectStore;

    expect($store->hasProjectConfig())->toBeTrue();
    expect($store->getProjectKey())->toBe('UP');
});

// ─── 3.2 linkProject() ───────────────────────────────────────────────────

it('linkProject creates .ticket-context.json with the project key', function (): void {
    $store = new ProjectStore;
    $store->linkProject('PROJ');

    expect(file_exists($this->tempDir.'/.ticket-context.json'))->toBeTrue();

    $data = json_decode(file_get_contents($this->tempDir.'/.ticket-context.json'), true);
    expect($data['project'])->toBe('PROJ');
});

it('subsequent call reads the same config file path', function (): void {
    $store = new ProjectStore;
    $store->linkProject('PROJ');

    $path = $store->getProjectConfigPath();
    expect($path)->toContain('.ticket-context.json');
    expect(file_exists($path))->toBeTrue();
});

// ─── 3.3 getProjectKey() ─────────────────────────────────────────────────

it('getProjectKey returns null when no config found', function (): void {
    $store = new ProjectStore;

    expect($store->getProjectKey())->toBeNull();
});

it('getProjectKey returns the stored project key', function (): void {
    file_put_contents($this->tempDir.'/.ticket-context.json', json_encode(['project' => 'MYPROJ', 'branches' => []]));

    $store = new ProjectStore;

    expect($store->getProjectKey())->toBe('MYPROJ');
});

// ─── 3.4 linkTicketsToBranch() ───────────────────────────────────────────

it('linkTicketsToBranch adds a new branch entry to the branches map', function (): void {
    file_put_contents($this->tempDir.'/.ticket-context.json', json_encode(['project' => 'PROJ', 'branches' => []]));

    $store = new ProjectStore;
    $store->linkTicketsToBranch('feature/proj-1-foo', ['PROJ-1']);

    $tickets = $store->getTicketsForBranch('feature/proj-1-foo');
    expect($tickets)->toBe(['PROJ-1']);
});

it('linkTicketsToBranch overwrites tickets when branch already exists', function (): void {
    file_put_contents($this->tempDir.'/.ticket-context.json', json_encode([
        'project' => 'PROJ',
        'branches' => ['feature/proj-1-foo' => ['PROJ-1']],
    ]));

    $store = new ProjectStore;
    $store->linkTicketsToBranch('feature/proj-1-foo', ['PROJ-1', 'PROJ-2']);

    expect($store->getTicketsForBranch('feature/proj-1-foo'))->toBe(['PROJ-1', 'PROJ-2']);
});

it('linkTicketsToBranch persists to the JSON file', function (): void {
    file_put_contents($this->tempDir.'/.ticket-context.json', json_encode(['project' => 'PROJ', 'branches' => []]));

    $store = new ProjectStore;
    $store->linkTicketsToBranch('feature/proj-99', ['PROJ-99']);

    $data = json_decode(file_get_contents($this->tempDir.'/.ticket-context.json'), true);
    expect($data['branches']['feature/proj-99'])->toBe(['PROJ-99']);
});

// ─── 3.5 getTicketsForBranch() ───────────────────────────────────────────

it('getTicketsForBranch returns empty array for unknown branch', function (): void {
    file_put_contents($this->tempDir.'/.ticket-context.json', json_encode(['project' => 'PROJ', 'branches' => []]));

    $store = new ProjectStore;

    expect($store->getTicketsForBranch('no-such-branch'))->toBe([]);
});

it('getTicketsForBranch returns the correct ticket array for known branch', function (): void {
    file_put_contents($this->tempDir.'/.ticket-context.json', json_encode([
        'project' => 'PROJ',
        'branches' => ['feature/proj-5-bar' => ['PROJ-5', 'PROJ-6']],
    ]));

    $store = new ProjectStore;

    expect($store->getTicketsForBranch('feature/proj-5-bar'))->toBe(['PROJ-5', 'PROJ-6']);
});

// ─── 3.6 getContextDir() ─────────────────────────────────────────────────

it('getContextDir returns absolute path to .claude/skills/ticket-context under project root', function (): void {
    file_put_contents($this->tempDir.'/.ticket-context.json', json_encode(['project' => 'PROJ', 'branches' => []]));

    $store = new ProjectStore;

    expect($store->getContextDir())->toBe($this->tempDir.'/.claude/skills/ticket-context');
});

it('getContextDir creates the directory if it does not exist', function (): void {
    file_put_contents($this->tempDir.'/.ticket-context.json', json_encode(['project' => 'PROJ', 'branches' => []]));

    $store = new ProjectStore;
    $dir = $store->getContextDir();

    expect(is_dir($dir))->toBeTrue();
});

// ─── 3.7 getCurrentBranch() ──────────────────────────────────────────────

it('getCurrentBranch returns null when not in a git repository', function (): void {
    // tempDir has no .git — not a git repo
    $store = new ProjectStore;

    expect($store->getCurrentBranch())->toBeNull();
});

it('getCurrentBranch returns branch name inside a git repo', function (): void {
    // Initialise a real git repo in tempDir
    exec('git init '.escapeshellarg($this->tempDir).' 2>&1');
    exec('git -C '.escapeshellarg($this->tempDir).' checkout -b main 2>&1');
    // Need at least one commit for HEAD to resolve
    exec('git -C '.escapeshellarg($this->tempDir).' commit --allow-empty -m "init" 2>&1');

    $store = new ProjectStore;

    expect($store->getCurrentBranch())->toBe('main');
});
