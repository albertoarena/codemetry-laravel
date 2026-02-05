<?php

declare(strict_types=1);

namespace Codemetry\Laravel;

use Codemetry\Core\Analyzer;
use Illuminate\Support\ServiceProvider;

final class CodemetryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/codemetry.php', 'codemetry');

        $this->app->singleton(Analyzer::class, fn() => new Analyzer());
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/codemetry.php' => config_path('codemetry.php'),
            ], 'codemetry-config');

            $this->commands([
                CodemetryAnalyzeCommand::class,
            ]);
        }
    }
}
