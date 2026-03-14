<?php

declare(strict_types=1);

// ─── Section 5: TixHelp ──────────────────────────────────────────────────

it('tix:help exits with SUCCESS', function (): void {
    $this->artisan('tix:help')->assertSuccessful();
});

it('tix:help output contains tix branch', function (): void {
    $this->artisan('tix:help')->expectsOutputToContain('tix branch');
});

it('tix:help output contains tix move', function (): void {
    $this->artisan('tix:help')->expectsOutputToContain('tix move');
});

it('tix:help output contains tix statuses', function (): void {
    $this->artisan('tix:help')->expectsOutputToContain('tix statuses');
});

it('tix:help output contains --transition flag', function (): void {
    $this->artisan('tix:help')->expectsOutputToContain('--transition');
});

it('tix:help output contains --no-branch flag', function (): void {
    $this->artisan('tix:help')->expectsOutputToContain('--no-branch');
});
