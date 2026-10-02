<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Process;

/**
 * Writes a timestamped pg_dump of the application database to storage/app/backups
 * (or a chosen path). Dependency-free — it shells out to pg_dump, so schedule it
 * from the OS/cron or Laravel's scheduler for routine off-site backups.
 */
class BackupDatabaseCommand extends Command
{
    protected $signature = 'backup:database {--path= : Directory to write the dump into (defaults to storage/app/backups)}';

    protected $description = 'Write a timestamped pg_dump of the application database';

    public function handle(): int
    {
        $default = config('database.default');
        if ($default !== 'pgsql') {
            $this->error("backup:database supports the pgsql connection only (current: {$default}).");

            return self::FAILURE;
        }

        $db = config('database.connections.pgsql');
        $dir = rtrim((string) ($this->option('path') ?: storage_path('app/backups')), '/\\');
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            $this->error("Could not create backup directory: {$dir}");

            return self::FAILURE;
        }

        $stamp = Carbon::now()->format('Ymd_His');
        $file = $dir.DIRECTORY_SEPARATOR.$db['database']."_{$stamp}.sql";
        $pgDump = env('PG_DUMP_PATH', 'pg_dump');

        $this->info("Backing up '{$db['database']}' → {$file}");

        $result = Process::timeout(600)
            ->env(['PGPASSWORD' => (string) ($db['password'] ?? '')])
            ->run([
                $pgDump,
                '-h', (string) $db['host'],
                '-p', (string) $db['port'],
                '-U', (string) $db['username'],
                '-d', (string) $db['database'],
                '--no-owner', '--no-privileges',
                '-f', $file,
            ]);

        if (! $result->successful()) {
            $this->error('pg_dump failed: '.trim($result->errorOutput() ?: $result->output()));
            $this->line('If pg_dump is not on PATH, set PG_DUMP_PATH in your .env.');

            return self::FAILURE;
        }

        $size = is_file($file) ? number_format(filesize($file) / 1024, 1).' KB' : 'unknown size';
        $this->info("Backup complete ({$size}).");

        return self::SUCCESS;
    }
}
