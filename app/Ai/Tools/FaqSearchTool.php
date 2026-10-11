<?php

namespace App\Ai\Tools;

class FaqSearchTool extends AbstractTool
{
    public function name(): string
    {
        return 'faq_search';
    }

    public function description(): string
    {
        return 'Search the MG Plastic admin knowledge base (Markdown) for answers to operational questions.';
    }

    public function inputSchema(): array
    {
        return $this->schema([
            'query' => [
                'type' => 'string',
                'description' => 'Search keywords in Arabic or English',
            ],
            'limit' => [
                'type' => 'integer',
                'description' => 'Max snippets to return (default 5)',
            ],
        ], ['query']);
    }

    public function handle(array $arguments): array
    {
        $query = trim((string) ($arguments['query'] ?? ''));
        $limit = max(1, min(10, (int) ($arguments['limit'] ?? 5)));

        if ($query === '') {
            return ['matches' => []];
        }

        $dir = (string) config('ai.knowledge_path');
        if (! is_dir($dir)) {
            return ['matches' => [], 'error' => 'Knowledge directory missing'];
        }

        $terms = preg_split('/\s+/u', mb_strtolower($query)) ?: [];
        $matches = [];

        foreach (glob($dir.DIRECTORY_SEPARATOR.'*.md') ?: [] as $file) {
            $body = (string) file_get_contents($file);
            $hay = mb_strtolower($body);
            $score = 0;
            foreach ($terms as $term) {
                if ($term !== '' && str_contains($hay, $term)) {
                    $score += substr_count($hay, $term);
                }
            }
            if ($score === 0) {
                continue;
            }

            $snippet = $this->snippetAround($body, $terms[0] ?? $query);
            $matches[] = [
                'file' => basename($file),
                'score' => $score,
                'snippet' => $snippet,
            ];
        }

        usort($matches, fn ($a, $b) => $b['score'] <=> $a['score']);

        return [
            'query' => $query,
            'matches' => array_slice($matches, 0, $limit),
        ];
    }

    /** @param list<string> $terms */
    private function snippetAround(string $body, string $term): string
    {
        $pos = mb_stripos($body, $term);
        if ($pos === false) {
            return mb_substr(trim($body), 0, 400);
        }

        $start = max(0, $pos - 160);
        $chunk = mb_substr($body, $start, 420);

        return trim(($start > 0 ? '…' : '').$chunk.'…');
    }
}
