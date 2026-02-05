<?php

declare(strict_types=1);

namespace Codemetry\Laravel;

use Codemetry\Core\Analyzer;
use Codemetry\Core\Domain\AnalysisRequest;
use Illuminate\Console\Command;

final class CodemetryAnalyzeCommand extends Command
{
    protected $signature = 'codemetry:analyze
        {--days=7 : Number of days to analyze}
        {--since= : Start date (ISO 8601)}
        {--until= : End date (ISO 8601)}
        {--author= : Filter by author name}
        {--branch= : Filter by branch}
        {--format=table : Output format (table or json)}
        {--ai= : Enable AI explanation (0 or 1)}
        {--baseline-days= : Override baseline days}
        {--follow-up-horizon= : Override follow-up horizon days}
        {--repo= : Repository path (defaults to base_path())}';

    protected $description = 'Analyze Git repository and produce mood proxy metrics';

    public function handle(Analyzer $analyzer): int
    {
        $repoPath = $this->option('repo') ?: base_path();
        $format = $this->option('format');

        try {
            $request = $this->buildRequest();
            $externalConfig = $this->buildExternalConfig();
            $result = $analyzer->analyze($repoPath, $request, $externalConfig);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($format === 'json') {
            $this->line($result->toJson(JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->renderTable($result);

        return self::SUCCESS;
    }

    private function buildRequest(): AnalysisRequest
    {
        $config = config('codemetry', []);

        $since = $this->option('since')
            ? new \DateTimeImmutable($this->option('since'))
            : null;

        $until = $this->option('until')
            ? new \DateTimeImmutable($this->option('until'))
            : null;

        $days = $since !== null && $until !== null
            ? null
            : (int) $this->option('days');

        $aiEnabled = $this->option('ai') !== null
            ? (bool) $this->option('ai')
            : ($config['ai']['enabled'] ?? false);

        return new AnalysisRequest(
            since: $since,
            until: $until,
            days: $days,
            author: $this->option('author') ?: null,
            branch: $this->option('branch') ?: null,
            baselineDays: (int) ($this->option('baseline-days') ?: ($config['baseline_days'] ?? 56)),
            followUpHorizonDays: (int) ($this->option('follow-up-horizon') ?: ($config['follow_up_horizon_days'] ?? 3)),
            aiEnabled: $aiEnabled,
            aiEngine: $config['ai']['engine'] ?? 'openai',
            outputFormat: $this->option('format'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildExternalConfig(): array
    {
        $config = config('codemetry', []);
        $aiConfig = $config['ai'] ?? [];

        return [
            'keywords' => $config['keywords'] ?? [],
            'ai' => [
                'api_key' => $aiConfig['api_key'] ?? null,
                'model' => $aiConfig['model'] ?? null,
                'base_url' => $aiConfig['base_url'] ?? null,
                'timeout' => $aiConfig['timeout'] ?? 30,
            ],
        ];
    }

    private function renderTable(\Codemetry\Core\Domain\AnalysisResult $result): void
    {
        $rows = [];

        foreach ($result->windows as $mood) {
            $reasons = array_map(
                fn($r) => $r->summary,
                array_slice($mood->reasons, 0, 3),
            );

            $rows[] = [
                $mood->windowLabel,
                $mood->moodLabel->value,
                $mood->moodScore,
                number_format($mood->confidence, 2),
                implode('; ', $reasons) ?: '-',
            ];
        }

        $this->table(
            ['Date', 'Mood', 'Score', 'Confidence', 'Top Reasons'],
            $rows,
        );
    }
}
