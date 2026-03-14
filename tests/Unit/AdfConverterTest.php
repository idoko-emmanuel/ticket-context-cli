<?php

declare(strict_types=1);

use App\Services\AdfConverter;

beforeEach(function (): void {
    $this->converter = new AdfConverter;
});

// ─── 1.1 Null / empty input ───────────────────────────────────────────────

it('returns empty string for null input', function (): void {
    expect($this->converter->toMarkdown(null))->toBe('');
});

it('returns empty string for empty array input', function (): void {
    expect($this->converter->toMarkdown([]))->toBe('');
});

it('returns empty string for doc with empty content', function (): void {
    expect($this->converter->toMarkdown(['type' => 'doc', 'content' => []]))->toBe('');
});

// ─── 1.2 Plain text ──────────────────────────────────────────────────────

it('renders a single text node as plain string', function (): void {
    $adf = [
        'type' => 'doc',
        'content' => [
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'Hello world'],
            ]],
        ],
    ];

    expect($this->converter->toMarkdown($adf))->toContain('Hello world');
});

it('wraps text with strong mark in **', function (): void {
    $adf = [
        'type' => 'doc',
        'content' => [
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'bold', 'marks' => [['type' => 'strong']]],
            ]],
        ],
    ];

    expect($this->converter->toMarkdown($adf))->toContain('**bold**');
});

it('wraps text with em mark in _', function (): void {
    $adf = [
        'type' => 'doc',
        'content' => [
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'italic', 'marks' => [['type' => 'em']]],
            ]],
        ],
    ];

    expect($this->converter->toMarkdown($adf))->toContain('_italic_');
});

it('wraps text with code mark in backticks', function (): void {
    $adf = [
        'type' => 'doc',
        'content' => [
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'myVar', 'marks' => [['type' => 'code']]],
            ]],
        ],
    ];

    expect($this->converter->toMarkdown($adf))->toContain('`myVar`');
});

it('wraps text with strike mark in ~~', function (): void {
    $adf = [
        'type' => 'doc',
        'content' => [
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'removed', 'marks' => [['type' => 'strike']]],
            ]],
        ],
    ];

    expect($this->converter->toMarkdown($adf))->toContain('~~removed~~');
});

it('wraps text with link mark in [text](href)', function (): void {
    $adf = [
        'type' => 'doc',
        'content' => [
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'click', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.com']]]],
            ]],
        ],
    ];

    expect($this->converter->toMarkdown($adf))->toContain('[click](https://example.com)');
});

it('defaults href to # when link mark has no href', function (): void {
    $adf = [
        'type' => 'doc',
        'content' => [
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'click', 'marks' => [['type' => 'link', 'attrs' => []]]],
            ]],
        ],
    ];

    expect($this->converter->toMarkdown($adf))->toContain('[click](#)');
});

it('applies multiple marks in order (bold + italic)', function (): void {
    $adf = [
        'type' => 'doc',
        'content' => [
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'text', 'marks' => [['type' => 'strong'], ['type' => 'em']]],
            ]],
        ],
    ];

    expect($this->converter->toMarkdown($adf))->toContain('_**text**_');
});

// ─── 1.3 Paragraphs ──────────────────────────────────────────────────────

it('renders paragraph text', function (): void {
    $adf = [
        'type' => 'doc',
        'content' => [
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'First'],
            ]],
        ],
    ];

    expect($this->converter->toMarkdown($adf))->toContain('First');
});

it('separates multiple paragraphs with a blank line', function (): void {
    $adf = [
        'type' => 'doc',
        'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Para one']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Para two']]],
        ],
    ];

    expect($this->converter->toMarkdown($adf))->toContain("Para one\n\nPara two");
});

it('renders hardBreak node as newline', function (): void {
    $adf = [
        'type' => 'doc',
        'content' => [
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'Line one'],
                ['type' => 'hardBreak'],
                ['type' => 'text', 'text' => 'Line two'],
            ]],
        ],
    ];

    expect($this->converter->toMarkdown($adf))->toContain("Line one\nLine two");
});

// ─── 1.4 Headings ────────────────────────────────────────────────────────

it('renders headings at correct levels', function (int $level, string $prefix): void {
    $adf = [
        'type' => 'doc',
        'content' => [
            ['type' => 'heading', 'attrs' => ['level' => $level], 'content' => [
                ['type' => 'text', 'text' => 'Title'],
            ]],
        ],
    ];

    expect($this->converter->toMarkdown($adf))->toContain("{$prefix} Title");
})->with([
    [1, '#'],
    [2, '##'],
    [3, '###'],
    [4, '####'],
    [5, '#####'],
    [6, '######'],
]);

// ─── 1.5 Lists ───────────────────────────────────────────────────────────

it('renders bulletList with - prefix per item', function (): void {
    $adf = [
        'type' => 'doc',
        'content' => [
            ['type' => 'bulletList', 'content' => [
                ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Item A']]]]],
                ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Item B']]]]],
            ]],
        ],
    ];

    $result = $this->converter->toMarkdown($adf);
    expect($result)->toContain('- Item A');
    expect($result)->toContain('- Item B');
});

it('renders orderedList with numbered prefix per item', function (): void {
    $adf = [
        'type' => 'doc',
        'content' => [
            ['type' => 'orderedList', 'content' => [
                ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'First']]]]],
                ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Second']]]]],
            ]],
        ],
    ];

    $result = $this->converter->toMarkdown($adf);
    expect($result)->toContain('1. First');
    expect($result)->toContain('2. Second');
});

// ─── 1.6 Code blocks ─────────────────────────────────────────────────────

it('renders codeBlock with language', function (): void {
    $adf = [
        'type' => 'doc',
        'content' => [
            ['type' => 'codeBlock', 'attrs' => ['language' => 'php'], 'content' => [
                ['type' => 'text', 'text' => 'echo "hi";'],
            ]],
        ],
    ];

    expect($this->converter->toMarkdown($adf))->toContain("```php\necho \"hi\";\n```");
});

it('renders codeBlock without language', function (): void {
    $adf = [
        'type' => 'doc',
        'content' => [
            ['type' => 'codeBlock', 'attrs' => [], 'content' => [
                ['type' => 'text', 'text' => 'some code'],
            ]],
        ],
    ];

    expect($this->converter->toMarkdown($adf))->toContain("```\nsome code\n```");
});

// ─── 1.7 Blockquote ──────────────────────────────────────────────────────

it('renders blockquote with > prefix on each line', function (): void {
    $adf = [
        'type' => 'doc',
        'content' => [
            ['type' => 'blockquote', 'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Quoted text']]],
            ]],
        ],
    ];

    expect($this->converter->toMarkdown($adf))->toContain('> Quoted text');
});

// ─── 1.8 Rule ────────────────────────────────────────────────────────────

it('renders rule as ---', function (): void {
    $adf = [
        'type' => 'doc',
        'content' => [
            ['type' => 'rule'],
        ],
    ];

    expect($this->converter->toMarkdown($adf))->toContain('---');
});

// ─── 1.9 Table ───────────────────────────────────────────────────────────

it('renders table with | separators and a separator row after headers', function (): void {
    $adf = [
        'type' => 'doc',
        'content' => [
            [
                'type' => 'table',
                'content' => [
                    [
                        'type' => 'tableRow',
                        'content' => [
                            ['type' => 'tableHeader', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Col A']]]]],
                            ['type' => 'tableHeader', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Col B']]]]],
                        ],
                    ],
                    [
                        'type' => 'tableRow',
                        'content' => [
                            ['type' => 'tableCell', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Val 1']]]]],
                            ['type' => 'tableCell', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Val 2']]]]],
                        ],
                    ],
                ],
            ],
        ],
    ];

    $result = $this->converter->toMarkdown($adf);
    expect($result)->toContain('| Col A | Col B |');
    expect($result)->toContain('| --- |');
    expect($result)->toContain('| Val 1 | Val 2 |');
});

it('renders empty table as empty string', function (): void {
    $adf = [
        'type' => 'doc',
        'content' => [
            ['type' => 'table', 'content' => []],
        ],
    ];

    expect($this->converter->toMarkdown($adf))->toBe('');
});

// ─── 1.10 Panel ──────────────────────────────────────────────────────────

it('renders panel with quoted block and uppercased type label', function (): void {
    $adf = [
        'type' => 'doc',
        'content' => [
            ['type' => 'panel', 'attrs' => ['panelType' => 'info'], 'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Note here']]],
            ]],
        ],
    ];

    $result = $this->converter->toMarkdown($adf);
    expect($result)->toContain('> **INFO:**');
    expect($result)->toContain('Note here');
});

// ─── 1.11 Mentions & emoji ───────────────────────────────────────────────

it('renders mention using text attribute', function (): void {
    $adf = [
        'type' => 'doc',
        'content' => [
            ['type' => 'paragraph', 'content' => [
                ['type' => 'mention', 'attrs' => ['text' => '@john.doe', 'id' => 'abc123']],
            ]],
        ],
    ];

    expect($this->converter->toMarkdown($adf))->toContain('@john.doe');
});

it('renders mention using id when text is absent', function (): void {
    $adf = [
        'type' => 'doc',
        'content' => [
            ['type' => 'paragraph', 'content' => [
                ['type' => 'mention', 'attrs' => ['id' => 'abc123']],
            ]],
        ],
    ];

    expect($this->converter->toMarkdown($adf))->toContain('@abc123');
});

it('renders emoji text attribute', function (): void {
    $adf = [
        'type' => 'doc',
        'content' => [
            ['type' => 'paragraph', 'content' => [
                ['type' => 'emoji', 'attrs' => ['text' => '🎉']],
            ]],
        ],
    ];

    expect($this->converter->toMarkdown($adf))->toContain('🎉');
});

// ─── 1.12 Unknown nodes ──────────────────────────────────────────────────

it('silently skips unknown node types without crashing', function (): void {
    $adf = [
        'type' => 'doc',
        'content' => [
            ['type' => 'unknownNodeType', 'content' => []],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'After unknown']]],
        ],
    ];

    expect($this->converter->toMarkdown($adf))->toContain('After unknown');
});
