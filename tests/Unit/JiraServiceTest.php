<?php

declare(strict_types=1);

use App\Services\AdfConverter;
use App\Services\ConfigStore;
use App\Services\JiraService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

/** Build a JiraService backed by a fake ConfigStore with known credentials. */
function makeJira(string $baseUrl = 'https://acme.atlassian.net'): JiraService
{
    $config = Mockery::mock(ConfigStore::class);
    $config->shouldReceive('getJiraCredentials')->andReturn([
        'base_url' => $baseUrl,
        'email' => 'user@acme.com',
        'token' => 'secret',
    ]);

    return new JiraService(new AdfConverter, $config);
}

/** Build a JiraService with empty credentials (unconfigured). */
function makeUnconfiguredJira(): JiraService
{
    $config = Mockery::mock(ConfigStore::class);
    $config->shouldReceive('getJiraCredentials')->andReturn([
        'base_url' => '',
        'email' => '',
        'token' => '',
    ]);

    return new JiraService(new AdfConverter, $config);
}

// ─── 4.1 testConnection() ────────────────────────────────────────────────

it('testConnection returns displayName on success', function (): void {
    Http::fake(['*' => Http::response(['displayName' => 'Alice'], 200)]);

    expect(makeJira()->testConnection())->toBe('Alice');
});

it('testConnection returns emailAddress when displayName is absent', function (): void {
    Http::fake(['*' => Http::response(['emailAddress' => 'alice@acme.com'], 200)]);

    expect(makeJira()->testConnection())->toBe('alice@acme.com');
});

it('testConnection returns Connected when both displayName and emailAddress are absent', function (): void {
    Http::fake(['*' => Http::response([], 200)]);

    expect(makeJira()->testConnection())->toBe('Connected');
});

it('testConnection throws RuntimeException on 401', function (): void {
    Http::fake(['*' => Http::response([], 401)]);

    expect(fn () => makeJira()->testConnection())->toThrow(RuntimeException::class, 'Authentication failed');
});

it('testConnection throws RuntimeException on 403', function (): void {
    Http::fake(['*' => Http::response([], 403)]);

    expect(fn () => makeJira()->testConnection())->toThrow(RuntimeException::class, 'Permission denied');
});

it('testConnection throws RuntimeException on 404', function (): void {
    Http::fake(['*' => Http::response([], 404)]);

    expect(fn () => makeJira()->testConnection())->toThrow(RuntimeException::class, 'Not found');
});

it('testConnection throws RuntimeException on 429', function (): void {
    Http::fake(['*' => Http::response([], 429)]);

    expect(fn () => makeJira()->testConnection())->toThrow(RuntimeException::class, 'Rate limited');
});

it('testConnection throws RuntimeException on ConnectionException', function (): void {
    Http::fake(function () {
        throw new ConnectionException('Connection refused');
    });

    expect(fn () => makeJira()->testConnection())->toThrow(RuntimeException::class, 'Could not connect');
});

it('testConnection throws RuntimeException when credentials are empty', function (): void {
    expect(fn () => makeUnconfiguredJira()->testConnection())->toThrow(RuntimeException::class, 'Jira is not configured');
});

// ─── 4.2 getActiveSprintIssues() ─────────────────────────────────────────

it('getActiveSprintIssues returns issues array from response', function (): void {
    Http::fake(['*' => Http::response(['issues' => [['key' => 'PROJ-1'], ['key' => 'PROJ-2']]], 200)]);

    $issues = makeJira()->getActiveSprintIssues();

    expect($issues)->toHaveCount(2);
    expect($issues[0]['key'])->toBe('PROJ-1');
});

it('getActiveSprintIssues prepends project filter when projectKey is provided', function (): void {
    Http::fake(['*' => Http::response(['issues' => []], 200)]);

    makeJira()->getActiveSprintIssues('PROJ');

    Http::assertSent(function (Request $request): bool {
        $body = $request->data();

        return str_starts_with((string) ($body['jql'] ?? ''), 'project = PROJ AND');
    });
});

it('getActiveSprintIssues does not prepend project filter when projectKey is null', function (): void {
    Http::fake(['*' => Http::response(['issues' => []], 200)]);

    makeJira()->getActiveSprintIssues(null);

    Http::assertSent(function (Request $request): bool {
        $body = $request->data();

        return ! str_starts_with((string) ($body['jql'] ?? ''), 'project =');
    });
});

it('getActiveSprintIssues returns empty array when response has no issues key', function (): void {
    Http::fake(['*' => Http::response([], 200)]);

    expect(makeJira()->getActiveSprintIssues())->toBe([]);
});

// ─── 4.3 getIssue() ──────────────────────────────────────────────────────

it('getIssue returns full issue array from response', function (): void {
    Http::fake(['*' => Http::response(['key' => 'PROJ-1', 'fields' => ['summary' => 'My issue']], 200)]);

    $issue = makeJira()->getIssue('PROJ-1');

    expect($issue['key'])->toBe('PROJ-1');
    expect($issue['fields']['summary'])->toBe('My issue');
});

it('getIssue throws RuntimeException with Not found on 404', function (): void {
    Http::fake(['*' => Http::response([], 404)]);

    expect(fn () => makeJira()->getIssue('PROJ-999'))->toThrow(RuntimeException::class, 'Not found');
});

// ─── 4.4 descriptionAsMarkdown() ─────────────────────────────────────────

it('descriptionAsMarkdown returns no-description placeholder when description is null', function (): void {
    $issue = ['fields' => ['description' => null]];

    expect(makeJira()->descriptionAsMarkdown($issue))->toBe('_No description provided._');
});

it('descriptionAsMarkdown returns no-description placeholder when description is empty string', function (): void {
    $issue = ['fields' => ['description' => '']];

    expect(makeJira()->descriptionAsMarkdown($issue))->toBe('_No description provided._');
});

it('descriptionAsMarkdown delegates ADF array to AdfConverter and returns result', function (): void {
    $issue = [
        'fields' => [
            'description' => [
                'type' => 'doc',
                'content' => [
                    ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'From ADF']]],
                ],
            ],
        ],
    ];

    expect(makeJira()->descriptionAsMarkdown($issue))->toContain('From ADF');
});

it('descriptionAsMarkdown returns plain string description unchanged', function (): void {
    $issue = ['fields' => ['description' => 'Plain text description']];

    expect(makeJira()->descriptionAsMarkdown($issue))->toBe('Plain text description');
});

// ─── 4.5 extractComments() ───────────────────────────────────────────────

it('extractComments returns empty array when there are no comments', function (): void {
    $issue = ['fields' => ['comment' => ['comments' => []]]];

    expect(makeJira()->extractComments($issue))->toBe([]);
});

it('extractComments extracts author, date, and body from comments', function (): void {
    $issue = [
        'fields' => [
            'comment' => [
                'comments' => [[
                    'author' => ['displayName' => 'Bob'],
                    'updated' => '2024-05-10T12:00:00.000+0000',
                    'body' => 'Plain comment',
                ]],
            ],
        ],
    ];

    $comments = makeJira()->extractComments($issue);

    expect($comments[0]['author'])->toBe('Bob');
    expect($comments[0]['date'])->toBe('2024-05-10');
    expect($comments[0]['body'])->toBe('Plain comment');
});

it('extractComments falls back to created when updated is missing', function (): void {
    $issue = [
        'fields' => [
            'comment' => [
                'comments' => [[
                    'author' => ['displayName' => 'Bob'],
                    'created' => '2024-03-01T09:00:00.000+0000',
                    'body' => 'Old comment',
                ]],
            ],
        ],
    ];

    $comments = makeJira()->extractComments($issue);

    expect($comments[0]['date'])->toBe('2024-03-01');
});

it('extractComments converts ADF comment body via AdfConverter', function (): void {
    $issue = [
        'fields' => [
            'comment' => [
                'comments' => [[
                    'author' => ['displayName' => 'Bob'],
                    'updated' => '2024-05-10T12:00:00.000+0000',
                    'body' => [
                        'type' => 'doc',
                        'content' => [
                            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'ADF comment body']]],
                        ],
                    ],
                ]],
            ],
        ],
    ];

    $comments = makeJira()->extractComments($issue);

    expect($comments[0]['body'])->toContain('ADF comment body');
});

it('extractComments returns plain string body unchanged', function (): void {
    $issue = [
        'fields' => [
            'comment' => [
                'comments' => [[
                    'author' => ['displayName' => 'Bob'],
                    'updated' => '2024-05-10T12:00:00.000+0000',
                    'body' => 'Just a string',
                ]],
            ],
        ],
    ];

    expect(makeJira()->extractComments($issue)[0]['body'])->toBe('Just a string');
});

// ─── 4.6 transitionIssue() ───────────────────────────────────────────────

it('transitionIssue returns false when GET transitions returns no transitions', function (): void {
    Http::fake(['*/transitions' => Http::response(['transitions' => []], 200)]);

    expect(makeJira()->transitionIssue('PROJ-1', 'In Progress'))->toBeFalse();
});

it('transitionIssue returns false when no transition name matches status', function (): void {
    Http::fake(['*/transitions' => Http::response([
        'transitions' => [['id' => '1', 'name' => 'Done']],
    ], 200)]);

    expect(makeJira()->transitionIssue('PROJ-1', 'In Progress'))->toBeFalse();
});

it('transitionIssue returns true and POSTs correct transition id when match found', function (): void {
    Http::fake([
        '*/transitions' => Http::response(['transitions' => [['id' => '42', 'name' => 'In Progress']]], 200),
        '*' => Http::response([], 204),
    ]);

    $result = makeJira()->transitionIssue('PROJ-1', 'In Progress');

    expect($result)->toBeTrue();

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '/transitions')
            && $request->method() === 'POST'
            && ($request->data()['transition']['id'] ?? null) === '42';
    });
});

it('transitionIssue matching is case-insensitive', function (): void {
    Http::fake([
        '*/transitions' => Http::response(['transitions' => [['id' => '5', 'name' => 'In Progress']]], 200),
        '*' => Http::response([], 204),
    ]);

    expect(makeJira()->transitionIssue('PROJ-1', 'in progress'))->toBeTrue();
});

// ─── 4.7 getProjectStatuses() ────────────────────────────────────────────

it('getProjectStatuses returns alphabetically sorted unique status names', function (): void {
    Http::fake(['*' => Http::response([
        ['statuses' => [['name' => 'Done'], ['name' => 'In Progress']]],
        ['statuses' => [['name' => 'In Progress'], ['name' => 'To Do']]],
    ], 200)]);

    $statuses = makeJira()->getProjectStatuses('PROJ');

    expect($statuses)->toBe(['Done', 'In Progress', 'To Do']);
});

it('getProjectStatuses deduplicates statuses across issue types', function (): void {
    Http::fake(['*' => Http::response([
        ['statuses' => [['name' => 'Done']]],
        ['statuses' => [['name' => 'Done'], ['name' => 'To Do']]],
    ], 200)]);

    expect(makeJira()->getProjectStatuses('PROJ'))->toBe(['Done', 'To Do']);
});

it('getProjectStatuses returns empty array when no statuses exist', function (): void {
    Http::fake(['*' => Http::response([], 200)]);

    expect(makeJira()->getProjectStatuses('PROJ'))->toBe([]);
});

it('getProjectStatuses throws RuntimeException on 404', function (): void {
    Http::fake(['*' => Http::response([], 404)]);

    expect(fn () => makeJira()->getProjectStatuses('BADPROJ'))->toThrow(RuntimeException::class, 'Not found');
});

// ─── 4.8 HTTP error codes ────────────────────────────────────────────────

it('throws correct message for 401', function (): void {
    Http::fake(['*' => Http::response([], 401)]);

    expect(fn () => makeJira()->testConnection())->toThrow(RuntimeException::class, 'Authentication failed');
});

it('throws correct message for 403', function (): void {
    Http::fake(['*' => Http::response([], 403)]);

    expect(fn () => makeJira()->testConnection())->toThrow(RuntimeException::class, 'Permission denied');
});

it('throws correct message for 404', function (): void {
    Http::fake(['*' => Http::response([], 404)]);

    expect(fn () => makeJira()->testConnection())->toThrow(RuntimeException::class, 'Not found');
});

it('throws correct message for 429', function (): void {
    Http::fake(['*' => Http::response([], 429)]);

    expect(fn () => makeJira()->testConnection())->toThrow(RuntimeException::class, 'Rate limited');
});

it('throws generic Jira API error message for 500', function (): void {
    Http::fake(['*' => Http::response([], 500)]);

    expect(fn () => makeJira()->testConnection())->toThrow(RuntimeException::class, 'Jira API error 500');
});
