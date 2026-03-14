<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Jira Cloud Connection
    |--------------------------------------------------------------------------
    |
    | Configuration for connecting to the Jira Cloud REST API v3.
    | All values are read from environment variables — never hard-code credentials.
    |
    */

    'base_url' => env('JIRA_BASE_URL', ''),

    'email' => env('JIRA_EMAIL', ''),

    'token' => env('JIRA_API_TOKEN', ''),

    /*
    |--------------------------------------------------------------------------
    | Optional Board Filter
    |--------------------------------------------------------------------------
    |
    | When set, sprint searches can be scoped to a specific board ID.
    | Leave null to search across all boards for open sprints.
    |
    */

    'board_id' => env('JIRA_BOARD_ID'),

    /*
    |--------------------------------------------------------------------------
    | JQL Defaults
    |--------------------------------------------------------------------------
    */

    'jql' => [
        'active_sprint' => env(
            'JIRA_JQL',
            'assignee = currentUser() AND sprint in openSprints() ORDER BY priority DESC, created DESC'
        ),
    ],

];
