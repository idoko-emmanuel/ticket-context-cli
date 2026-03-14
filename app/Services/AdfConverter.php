<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Converts Atlassian Document Format (ADF) nodes to plain Markdown.
 *
 * ADF is the rich-text format returned by Jira Cloud API v3. This converter
 * handles the most common node types found in ticket descriptions and comments.
 */
class AdfConverter
{
    /**
     * Convert an ADF document array to a Markdown string.
     *
     * @param  array<string, mixed>|null  $adf
     */
    public function toMarkdown(?array $adf): string
    {
        if (empty($adf) || ! isset($adf['content'])) {
            return '';
        }

        return trim($this->renderNodes($adf['content']));
    }

    /**
     * Render an array of ADF content nodes recursively.
     *
     * @param  array<int, array<string, mixed>>  $nodes
     */
    private function renderNodes(array $nodes): string
    {
        return implode('', array_map(fn (array $node) => $this->renderNode($node), $nodes));
    }

    /**
     * Render a single ADF node to Markdown.
     *
     * @param  array<string, mixed>  $node
     */
    private function renderNode(array $node): string
    {
        $type = $node['type'] ?? 'unknown';
        $content = $node['content'] ?? [];
        $text = $node['text'] ?? '';
        $attrs = $node['attrs'] ?? [];

        return match ($type) {
            'doc' => $this->renderNodes($content),
            'paragraph' => $this->renderNodes($content)."\n\n",
            'text' => $this->applyMarks($text, $node['marks'] ?? []),
            'hardBreak' => "\n",
            'heading' => $this->renderHeading($content, (int) ($attrs['level'] ?? 1)),
            'bulletList' => $this->renderList($content, ordered: false),
            'orderedList' => $this->renderList($content, ordered: true),
            'listItem' => $this->renderNodes($content),
            'blockquote' => $this->renderBlockquote($content),
            'codeBlock' => $this->renderCodeBlock($content, (string) ($attrs['language'] ?? '')),
            'rule' => "---\n\n",
            'mention' => '@'.($attrs['text'] ?? $attrs['id'] ?? 'unknown'),
            'emoji' => ($attrs['text'] ?? ':emoji:'),
            'inlineCard' => ($attrs['url'] ?? ''),
            'mediaGroup',
            'mediaSingle',
            'media' => '',
            'table' => $this->renderTable($content),
            'tableRow' => $this->renderTableRow($content),
            'tableCell',
            'tableHeader' => $this->renderNodes($content),
            'panel' => $this->renderPanel($content, (string) ($attrs['panelType'] ?? 'info')),
            'expand' => $this->renderNodes($content),
            default => $this->renderNodes($content),
        };
    }

    /**
     * Apply inline marks (bold, italic, code, link, strike) to a text string.
     *
     * @param  array<int, array<string, mixed>>  $marks
     */
    private function applyMarks(string $text, array $marks): string
    {
        foreach ($marks as $mark) {
            $text = match ($mark['type'] ?? '') {
                'strong' => "**{$text}**",
                'em' => "_{$text}_",
                'code' => "`{$text}`",
                'strike' => "~~{$text}~~",
                'link' => "[{$text}](".($mark['attrs']['href'] ?? '#').')',
                default => $text,
            };
        }

        return $text;
    }

    /**
     * @param  array<int, array<string, mixed>>  $content
     */
    private function renderHeading(array $content, int $level): string
    {
        $prefix = str_repeat('#', max(1, min(6, $level)));

        return $prefix.' '.$this->renderNodes($content)."\n\n";
    }

    /**
     * @param  array<int, array<string, mixed>>  $content
     */
    private function renderList(array $content, bool $ordered): string
    {
        $output = '';
        foreach ($content as $index => $item) {
            $prefix = $ordered ? ($index + 1).'. ' : '- ';
            $body = trim($this->renderNode($item));
            $output .= $prefix.$body."\n";
        }

        return $output."\n";
    }

    /**
     * @param  array<int, array<string, mixed>>  $content
     */
    private function renderBlockquote(array $content): string
    {
        $inner = trim($this->renderNodes($content));
        $lines = explode("\n", $inner);

        return implode("\n", array_map(fn (string $l) => '> '.$l, $lines))."\n\n";
    }

    /**
     * @param  array<int, array<string, mixed>>  $content
     */
    private function renderCodeBlock(array $content, string $language): string
    {
        $code = '';
        foreach ($content as $node) {
            $code .= $node['text'] ?? '';
        }

        return "```{$language}\n{$code}\n```\n\n";
    }

    /**
     * @param  array<int, array<string, mixed>>  $content
     */
    private function renderTable(array $content): string
    {
        $rows = array_map(fn (array $row) => $this->renderTableRow($row['content'] ?? []), $content);

        if (empty($rows)) {
            return '';
        }

        $output = $rows[0];
        $columnCount = substr_count($rows[0], '|') - 1;
        $separator = '| '.implode(' | ', array_fill(0, max(1, $columnCount), '---'))." |\n";
        $output .= $separator;

        foreach (array_slice($rows, 1) as $row) {
            $output .= $row;
        }

        return $output."\n";
    }

    /**
     * @param  array<int, array<string, mixed>>  $content
     */
    private function renderTableRow(array $content): string
    {
        $cells = array_map(fn (array $cell) => trim($this->renderNodes($cell['content'] ?? [])), $content);

        return '| '.implode(' | ', $cells)." |\n";
    }

    /**
     * @param  array<int, array<string, mixed>>  $content
     */
    private function renderPanel(array $content, string $panelType): string
    {
        $label = strtoupper($panelType);
        $inner = trim($this->renderNodes($content));

        return "> **{$label}:** {$inner}\n\n";
    }
}
