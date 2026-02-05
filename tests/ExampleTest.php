<?php

use Codemetry\Core\Analyzer;
use Symfony\Component\Process\Process;

function createLaravelTestRepo(): string
{
    $dir = sys_get_temp_dir() . '/codemetry-laravel-' . uniqid();
    mkdir($dir, 0755, true);

    (new Process(['git', 'init'], $dir))->mustRun();
    (new Process(['git', 'config', 'user.email', 'test@example.com'], $dir))->mustRun();
    (new Process(['git', 'config', 'user.name', 'Test User'], $dir))->mustRun();

    return $dir;
}

function commitInRepo(string $dir, string $file, string $content, string $message, string $date): void
{
    $fullPath = $dir . '/' . $file;
    $parentDir = dirname($fullPath);
    if (!is_dir($parentDir)) {
        mkdir($parentDir, 0755, true);
    }
    file_put_contents($fullPath, $content);
    (new Process(['git', 'add', $file], $dir))->mustRun();

    $env = array_merge($_ENV, [
        'GIT_AUTHOR_DATE' => $date,
        'GIT_COMMITTER_DATE' => $date,
    ]);

    (new Process(['git', 'commit', '-m', $message], $dir, $env))->mustRun();
}

function destroyLaravelRepo(string $dir): void
{
    (new Process(['rm', '-rf', $dir]))->run();
}

// --- Service Provider ---

test('service provider registers Analyzer singleton', function () {
    $analyzer1 = $this->app->make(Analyzer::class);
    $analyzer2 = $this->app->make(Analyzer::class);

    expect($analyzer1)->toBeInstanceOf(Analyzer::class)
        ->and($analyzer1)->toBe($analyzer2);
});

test('service provider merges default config', function () {
    expect(config('codemetry.baseline_days'))->toBe(56)
        ->and(config('codemetry.follow_up_horizon_days'))->toBe(3)
        ->and(config('codemetry.keywords.fix_pattern'))->not->toBeEmpty()
        ->and(config('codemetry.ai.enabled'))->toBeFalse();
});

test('service provider registers artisan command', function () {
    $commands = array_keys(\Illuminate\Support\Facades\Artisan::all());

    expect($commands)->toContain('codemetry:analyze');
});

// --- Command: JSON output ---

test('command outputs valid JSON', function () {
    $dir = createLaravelTestRepo();

    commitInRepo($dir, 'init.txt', 'init', 'init', '2024-01-10T10:00:00+00:00');
    commitInRepo($dir, 'file.php', "<?php\necho 1;\n", 'feat: add file', '2024-01-15T10:00:00+00:00');

    $this->artisan('codemetry:analyze', [
        '--repo' => $dir,
        '--since' => '2024-01-15',
        '--until' => '2024-01-16',
        '--format' => 'json',
        '--baseline-days' => 3,
    ])->assertSuccessful();

    destroyLaravelRepo($dir);
});

test('command JSON output matches schema', function () {
    $dir = createLaravelTestRepo();

    commitInRepo($dir, 'init.txt', 'init', 'init', '2024-01-10T10:00:00+00:00');
    commitInRepo($dir, 'file.php', "<?php\necho 1;\n", 'feat: add file', '2024-01-15T10:00:00+00:00');
    commitInRepo($dir, 'file.php', "<?php\necho 2;\n", 'fix: typo', '2024-01-15T14:00:00+00:00');

    $this->artisan('codemetry:analyze', [
        '--repo' => $dir,
        '--since' => '2024-01-15',
        '--until' => '2024-01-16',
        '--format' => 'json',
        '--baseline-days' => 3,
    ])->expectsOutputToContain('"schema_version"')
        ->assertSuccessful();

    destroyLaravelRepo($dir);
});

// --- Command: Table output ---

test('command outputs table format by default', function () {
    $dir = createLaravelTestRepo();

    commitInRepo($dir, 'init.txt', 'init', 'init', '2024-01-10T10:00:00+00:00');
    commitInRepo($dir, 'file.php', "<?php\necho 1;\n", 'feat: add file', '2024-01-15T10:00:00+00:00');

    $this->artisan('codemetry:analyze', [
        '--repo' => $dir,
        '--since' => '2024-01-15',
        '--until' => '2024-01-16',
        '--baseline-days' => 3,
    ])->expectsOutputToContain('2024-01-15')
        ->assertSuccessful();

    destroyLaravelRepo($dir);
});

// --- Command: Error handling ---

test('command shows error for invalid repo path', function () {
    $this->artisan('codemetry:analyze', [
        '--repo' => '/nonexistent/path',
        '--days' => 7,
    ])->assertFailed();
});

// --- Command: Option passthrough ---

test('command passes author filter to analyzer', function () {
    $dir = createLaravelTestRepo();

    commitInRepo($dir, 'init.txt', 'init', 'init', '2024-01-10T10:00:00+00:00');

    // Alice's commit
    $env = array_merge($_ENV, [
        'GIT_AUTHOR_DATE' => '2024-01-15T10:00:00+00:00',
        'GIT_COMMITTER_DATE' => '2024-01-15T10:00:00+00:00',
        'GIT_AUTHOR_NAME' => 'Alice',
        'GIT_AUTHOR_EMAIL' => 'alice@example.com',
    ]);
    file_put_contents($dir . '/alice.txt', 'alice work');
    (new Process(['git', 'add', 'alice.txt'], $dir))->mustRun();
    (new Process(['git', 'commit', '-m', 'feat: alice work'], $dir, $env))->mustRun();

    // Bob's commit
    $env['GIT_AUTHOR_NAME'] = 'Bob';
    $env['GIT_AUTHOR_EMAIL'] = 'bob@example.com';
    file_put_contents($dir . '/bob.txt', 'bob work');
    (new Process(['git', 'add', 'bob.txt'], $dir))->mustRun();
    (new Process(['git', 'commit', '-m', 'feat: bob work'], $dir, $env))->mustRun();

    $this->artisan('codemetry:analyze', [
        '--repo' => $dir,
        '--since' => '2024-01-15',
        '--until' => '2024-01-16',
        '--format' => 'json',
        '--author' => 'Alice',
        '--baseline-days' => 3,
    ])->expectsOutputToContain('"schema_version"')
        ->assertSuccessful();

    destroyLaravelRepo($dir);
});

// --- Config override ---

test('command uses config defaults when options not provided', function () {
    $dir = createLaravelTestRepo();

    commitInRepo($dir, 'init.txt', 'init', 'init', '2024-01-10T10:00:00+00:00');
    commitInRepo($dir, 'file.php', "<?php\necho 1;\n", 'feat: add file', '2024-01-15T10:00:00+00:00');

    config()->set('codemetry.baseline_days', 10);

    $this->artisan('codemetry:analyze', [
        '--repo' => $dir,
        '--since' => '2024-01-15',
        '--until' => '2024-01-16',
        '--format' => 'json',
    ])->expectsOutputToContain('"baseline_days": 10')
        ->assertSuccessful();

    destroyLaravelRepo($dir);
});
