<?php

declare(strict_types=1);

namespace Codemetry\Laravel;

use Codemetry\Core\Analyzer;
use Codemetry\Core\Domain\AnalysisRequest;
use Codemetry\Core\Domain\AnalysisResult;
use Codemetry\Core\Domain\Confounder;
use Codemetry\Core\Exception\InvalidRepoException;
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
        {--ai-engine= : AI engine to use (openai, anthropic, deepseek, google)}
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
        } catch (InvalidRepoException $e) {
            $this->error('Invalid Git repository: ' . $e->getMessage());
            $this->line('');
            $this->line('  <fg=yellow>Hint:</> Ensure the path points to a valid Git repository with commit history.');

            return self::FAILURE;
        } catch (\InvalidArgumentException $e) {
            $this->error('Invalid argument: ' . $e->getMessage());
            $this->line('');
            $this->line('  <fg=yellow>Hint:</> Check date format (use ISO 8601, e.g., 2024-01-15).');

            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->error('Analysis failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        // Warn if AI was requested but unavailable
        $this->warnIfAiUnavailable($request, $result);

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

        $since = $this->parseDate($this->option('since'), 'since');
        $until = $this->parseDate($this->option('until'), 'until');

        $days = $since !== null && $until !== null
            ? null
            : (int) $this->option('days');

        $aiEnabled = $this->option('ai') !== null
            ? (bool) $this->option('ai')
            : ($config['ai']['enabled'] ?? false);

        $aiEngine = $this->option('ai-engine') ?: ($config['ai']['engine'] ?? 'openai');

        return new AnalysisRequest(
            since: $since,
            until: $until,
            days: $days,
            author: $this->option('author') ?: null,
            branch: $this->option('branch') ?: null,
            baselineDays: (int) ($this->option('baseline-days') ?: ($config['baseline_days'] ?? 56)),
            followUpHorizonDays: (int) ($this->option('follow-up-horizon') ?: ($config['follow_up_horizon_days'] ?? 3)),
            aiEnabled: $aiEnabled,
            aiEngine: $aiEngine,
            outputFormat: $this->option('format'),
        );
    }

    /**
     * Parse a date option with clear error message.
     */
    private function parseDate(?string $value, string $optionName): ?\DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            throw new \InvalidArgumentException(
                "Invalid --{$optionName} date: '{$value}'. Use ISO 8601 format (e.g., 2024-01-15)."
            );
        }
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

    /**
     * Warn if AI was requested but unavailable.
     */
    private function warnIfAiUnavailable(AnalysisRequest $request, AnalysisResult $result): void
    {
        if (!$request->aiEnabled || empty($result->windows)) {
            return;
        }

        foreach ($result->windows as $mood) {
            if (in_array(Confounder::AI_UNAVAILABLE, $mood->confounders, true)) {
                $this->warn('AI enhancement was requested but unavailable.');
                $this->line('  <fg=yellow>Hint:</> Set CODEMETRY_AI_API_KEY in your .env file.');
                $this->newLine();

                return;
            }
        }
    }

    private function renderTable(AnalysisResult $result): void
    {
        $rows = [];

        foreach ($result->windows as $mood) {
            // Format reasons with bullets and truncation indicator
            $reasonCount = count($mood->reasons);
            $topReasons = array_slice($mood->reasons, 0, 3);
            $reasonsList = array_map(fn($r) => $r->summary, $topReasons);
            $reasonsText = implode("\n", $reasonsList) ?: '-';
            if ($reasonCount > 3) {
                $reasonsText .= "\n(+" . ($reasonCount - 3) . ' more)';
            }

            $rows[] = [
                $mood->windowLabel,
                $mood->moodLabel->value,
                $mood->moodScore . '%',
                number_format($mood->confidence * 100, 0) . '%',
                $reasonsText,
            ];
        }

        $this->table(
            ['Date', 'Mood', 'Score', 'Confidence', 'Top Reasons'],
            $rows,
        );

        // Display AI summaries if present
        $this->renderAiSummaries($result);
    }

    /**
     * Render AI summaries after the table.
     */
    private function renderAiSummaries(AnalysisResult $result): void
    {
        foreach ($result->windows as $mood) {
            if ($mood->aiSummary !== null && !empty($mood->aiSummary->explanationBullets)) {
                $this->newLine();
                $this->info("AI Insights for {$mood->windowLabel}:");
                foreach ($mood->aiSummary->explanationBullets as $bullet) {
                    $this->line("  - {$bullet}");
                }
            }
        }
    }
}
