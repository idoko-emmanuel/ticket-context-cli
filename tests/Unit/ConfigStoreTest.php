<?php

declare(strict_types=1);

use App\Services\ConfigStore;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    $this->tempHome = sys_get_temp_dir().'/config-store-test-'.uniqid();
    mkdir($this->tempHome, 0700, true);
    $this->originalHome = $_SERVER['HOME'] ?? null;
    $_SERVER['HOME'] = $this->tempHome;

    // Clear config fallback so tests are not contaminated by real .env values
    config(['jira.base_url' => '', 'jira.email' => '', 'jira.token' => '']);
});

afterEach(function (): void {
    if ($this->originalHome === null) {
        unset($_SERVER['HOME']);
    } else {
        $_SERVER['HOME'] = $this->originalHome;
    }

    // Clean up temp directory recursively
    $configFile = $this->tempHome.'/.config/ticket-context/config.json';
    if (file_exists($configFile)) {
        unlink($configFile);
    }
    foreach ([
        $this->tempHome.'/.config/ticket-context',
        $this->tempHome.'/.config',
        $this->tempHome,
    ] as $dir) {
        if (is_dir($dir)) {
            @rmdir($dir);
        }
    }
});

// ─── 2.1 Path resolution ─────────────────────────────────────────────────

it('getConfigPath returns the correct path under home', function (): void {
    $store = new ConfigStore;

    expect($store->getConfigPath())->toBe($this->tempHome.'/.config/ticket-context/config.json');
});

// ─── 2.2 isConfigured() ──────────────────────────────────────────────────

it('isConfigured returns false when config file does not exist', function (): void {
    $store = new ConfigStore;

    expect($store->isConfigured())->toBeFalse();
});

it('isConfigured returns false when file exists but fields are empty', function (): void {
    $configDir = $this->tempHome.'/.config/ticket-context';
    mkdir($configDir, 0700, true);
    file_put_contents($configDir.'/config.json', json_encode(['jira' => ['base_url' => '', 'email' => '', 'token' => '']]));

    $store = new ConfigStore;

    expect($store->isConfigured())->toBeFalse();
});

it('isConfigured returns true when all credentials are present', function (): void {
    $configDir = $this->tempHome.'/.config/ticket-context';
    mkdir($configDir, 0700, true);
    file_put_contents($configDir.'/config.json', json_encode([
        'jira' => ['base_url' => 'https://acme.atlassian.net', 'email' => 'user@acme.com', 'token' => 'abc123'],
    ]));

    $store = new ConfigStore;

    expect($store->isConfigured())->toBeTrue();
});

// ─── 2.3 getJiraCredentials() ────────────────────────────────────────────

it('getJiraCredentials returns empty strings when no file exists', function (): void {
    $store = new ConfigStore;

    expect($store->getJiraCredentials())->toBe(['base_url' => '', 'email' => '', 'token' => '']);
});

it('getJiraCredentials returns credentials from file when it exists', function (): void {
    $configDir = $this->tempHome.'/.config/ticket-context';
    mkdir($configDir, 0700, true);
    file_put_contents($configDir.'/config.json', json_encode([
        'jira' => ['base_url' => 'https://acme.atlassian.net', 'email' => 'me@acme.com', 'token' => 'tok'],
    ]));

    $store = new ConfigStore;
    $creds = $store->getJiraCredentials();

    expect($creds['base_url'])->toBe('https://acme.atlassian.net');
    expect($creds['email'])->toBe('me@acme.com');
    expect($creds['token'])->toBe('tok');
});

// ─── 2.4 saveJiraCredentials() ───────────────────────────────────────────

it('saveJiraCredentials creates config directory if it does not exist', function (): void {
    $store = new ConfigStore;
    $store->saveJiraCredentials('https://acme.atlassian.net', 'me@acme.com', 'tok');

    expect(is_dir($this->tempHome.'/.config/ticket-context'))->toBeTrue();
});

it('saveJiraCredentials creates config file with correct JSON structure', function (): void {
    $store = new ConfigStore;
    $store->saveJiraCredentials('https://acme.atlassian.net', 'me@acme.com', 'tok');

    $data = json_decode(file_get_contents($this->tempHome.'/.config/ticket-context/config.json'), true);
    expect($data['jira']['base_url'])->toBe('https://acme.atlassian.net');
    expect($data['jira']['email'])->toBe('me@acme.com');
    expect($data['jira']['token'])->toBe('tok');
});

it('saveJiraCredentials sets file permissions to 0600', function (): void {
    $store = new ConfigStore;
    $store->saveJiraCredentials('https://acme.atlassian.net', 'me@acme.com', 'tok');

    $perms = fileperms($this->tempHome.'/.config/ticket-context/config.json') & 0777;
    expect($perms)->toBe(0600);
});

it('saveJiraCredentials strips trailing slash from base_url before saving', function (): void {
    $store = new ConfigStore;
    $store->saveJiraCredentials('https://acme.atlassian.net/', 'me@acme.com', 'tok');

    $creds = $store->getJiraCredentials();
    expect($creds['base_url'])->toBe('https://acme.atlassian.net');
});

it('saveJiraCredentials overwrites existing config on re-save', function (): void {
    $store = new ConfigStore;
    $store->saveJiraCredentials('https://old.atlassian.net', 'old@acme.com', 'oldtok');
    $store->saveJiraCredentials('https://new.atlassian.net', 'new@acme.com', 'newtok');

    $creds = $store->getJiraCredentials();
    expect($creds['base_url'])->toBe('https://new.atlassian.net');
    expect($creds['email'])->toBe('new@acme.com');
    expect($creds['token'])->toBe('newtok');
});

it('credentials can be read back after saving', function (): void {
    $store = new ConfigStore;
    $store->saveJiraCredentials('https://acme.atlassian.net', 'me@acme.com', 'tok');

    $fresh = new ConfigStore;
    $creds = $fresh->getJiraCredentials();

    expect($creds['base_url'])->toBe('https://acme.atlassian.net');
    expect($creds['email'])->toBe('me@acme.com');
    expect($creds['token'])->toBe('tok');
});
