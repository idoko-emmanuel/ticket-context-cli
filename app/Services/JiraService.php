<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Jira Cloud REST API v3 client.
 *
 * Wraps all HTTP communication with the Jira API, handling authentication,
 * error normalisation, and basic response shaping. Credentials are sourced
 * from ConfigStore (global ~/.config/ticket-context/config.json) with fallback
 * to Laravel env config.
 */
class JiraService
{
    private string $baseUrl;

    private string $email;

    private string $token;

    public function __construct(
        private readonly AdfConverter $adf,
        private readonly ConfigStore $config,
    ) {
        $credentials = $this->config->getJiraCredentials();
        $this->baseUrl = rtrim($credentials['base_url'], '/');
        $this->email = $credentials['email'];
        $this->token = $credentials['token'];
    }

    /**
     * Test the connection by fetching the current user profile.
     * Returns the display name on success.
     *
     * @throws RuntimeException
     */
    public function testConnection(): string
    {
        $data = $this->request('/rest/api/3/myself');

        return (string) ($data['displayName'] ?? $data['emailAddress'] ?? 'Connected');
    }

    /**
     * Fetch all issues assigned to the current user in open sprints.
     * Optionally scoped to a Jira project key.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws RuntimeException
     */
    public function getActiveSprintIssues(?string $projectKey = null): array
    {
        $jql = (string) config('jira.jql.active_sprint');

        if ($projectKey !== null) {
            $jql = "project = {$projectKey} AND ".$jql;
        }

        $response = $this->post('/rest/api/3/search/jql', [
            'jql' => $jql,
            'maxResults' => 50,
            'fields' => ['summary', 'status', 'issuetype', 'priority', 'assignee'],
        ]);

        return $response['issues'] ?? [];
    }

    /**
     * Fetch a single issue by key with full field detail.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException
     */
    public function getIssue(string $key): array
    {
        return $this->request("/rest/api/3/issue/{$key}", [
            'fields' => 'summary,status,issuetype,priority,assignee,description,comment,customfield_10016',
        ]);
    }

    /**
     * Extract and convert the description from an issue to Markdown.
     *
     * @param  array<string, mixed>  $issue
     */
    public function descriptionAsMarkdown(array $issue): string
    {
        $description = $issue['fields']['description'] ?? null;

        if (empty($description)) {
            return '_No description provided._';
        }

        if (\is_array($description) && ($description['type'] ?? '') === 'doc') {
            return $this->adf->toMarkdown($description);
        }

        return (string) $description;
    }

    /**
     * Transition an issue to the named status.
     *
     * Fetches available transitions and posts the first whose name matches
     * $toStatus (case-insensitive). Returns true if the transition was applied,
     * false if no matching transition was found (e.g. already in that status).
     *
     * @throws RuntimeException
     */
    public function transitionIssue(string $key, string $toStatus): bool
    {
        $data = $this->request("/rest/api/3/issue/{$key}/transitions");
        $transitions = $data['transitions'] ?? [];

        $transition = null;
        foreach ($transitions as $t) {
            if (strtolower((string) ($t['name'] ?? '')) === strtolower($toStatus)) {
                $transition = $t;
                break;
            }
        }

        if ($transition === null) {
            return false;
        }

        $this->post("/rest/api/3/issue/{$key}/transitions", [
            'transition' => ['id' => $transition['id']],
        ]);

        return true;
    }

    /**
     * Return the unique status names available in a Jira project, sorted alphabetically.
     *
     * @return string[]
     *
     * @throws RuntimeException
     */
    public function getProjectStatuses(string $projectKey): array
    {
        $issueTypes = $this->request("/rest/api/3/project/{$projectKey}/statuses");

        $statuses = [];
        foreach ($issueTypes as $issueType) {
            foreach ($issueType['statuses'] ?? [] as $status) {
                $name = (string) ($status['name'] ?? '');
                if ($name !== '' && ! \in_array($name, $statuses, true)) {
                    $statuses[] = $name;
                }
            }
        }

        sort($statuses);

        return $statuses;
    }

    /**
     * Extract all comments from an issue, returning them in chronological order.
     *
     * @param  array<string, mixed>  $issue
     * @return array<int, array{author: string, date: string, body: string}>
     */
    public function extractComments(array $issue): array
    {
        $comments = $issue['fields']['comment']['comments'] ?? [];

        return array_map(function (array $comment): array {
            $body = $comment['body'] ?? null;

            $bodyText = \is_array($body) && ($body['type'] ?? '') === 'doc'
                ? $this->adf->toMarkdown($body)
                : (string) ($body ?? '');

            return [
                'author' => $comment['author']['displayName'] ?? 'Unknown',
                'date' => substr((string) ($comment['updated'] ?? $comment['created'] ?? ''), 0, 10),
                'body' => $bodyText,
            ];
        }, $comments);
    }

    /**
     * Send an authenticated POST request to the Jira REST API.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     *
     * @throws RuntimeException
     */
    private function post(string $path, array $body = []): array
    {
        if (empty($this->baseUrl) || empty($this->email) || empty($this->token)) {
            throw new RuntimeException('Jira is not configured. Run: tix configure');
        }

        try {
            $response = Http::withBasicAuth($this->email, $this->token)
                ->acceptJson()
                ->post("{$this->baseUrl}{$path}", $body);
        } catch (ConnectionException $e) {
            throw new RuntimeException("Could not connect to Jira: {$e->getMessage()}");
        }

        $this->assertSuccessful($response, $path);

        return $response->json() ?? [];
    }

    /**
     * Send an authenticated GET request to the Jira REST API.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     *
     * @throws RuntimeException
     */
    private function request(string $path, array $query = []): array
    {
        if (empty($this->baseUrl) || empty($this->email) || empty($this->token)) {
            throw new RuntimeException(
                'Jira is not configured. Run: tix configure'
            );
        }

        try {
            $response = Http::withBasicAuth($this->email, $this->token)
                ->acceptJson()
                ->get("{$this->baseUrl}{$path}", $query);
        } catch (ConnectionException $e) {
            throw new RuntimeException("Could not connect to Jira: {$e->getMessage()}");
        }

        $this->assertSuccessful($response, $path);

        return $response->json() ?? [];
    }

    /**
     * Assert the response was successful, throwing a descriptive error otherwise.
     *
     * @throws RuntimeException
     */
    private function assertSuccessful(Response $response, string $path): void
    {
        if ($response->successful()) {
            return;
        }

        $status = $response->status();

        $message = match ($status) {
            401 => 'Authentication failed — check your email and API token (run: tix configure).',
            403 => 'Permission denied — your account may lack access to this resource.',
            404 => "Not found: {$path}",
            429 => 'Rate limited by Jira — please wait a moment and try again.',
            default => "Jira API error {$status}: ".($response->json('errorMessages.0') ?? $response->body()),
        };

        throw new RuntimeException($message);
    }
}
