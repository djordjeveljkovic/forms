<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'app:deploy', description: 'Cache views, config, routes, and events for production.')]
class DeployCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'app:deploy {--no-migrate : Skip running database migrations}';

    /**
     * @var string
     */
    protected $description = 'Run the standard production caching and migration steps.';

    public function handle(): int
    {
        $this->info('Optimising for production…');

        if (! $this->option('no-migrate')) {
            $this->components->task('Running migrations', function () {
                $this->call('migrate', ['--force' => true, '--no-interaction' => true]);
            });
        }

        $this->components->task('Caching views', fn () => $this->call('view:cache') === 0);
        $this->components->task('Caching config', fn () => $this->call('config:cache') === 0);
        $this->components->task('Caching routes', fn () => $this->call('route:cache') === 0);
        $this->components->task('Caching events', fn () => $this->call('event:cache') === 0);

        $this->info('Optimisation complete.');

        return self::SUCCESS;
    }
}
