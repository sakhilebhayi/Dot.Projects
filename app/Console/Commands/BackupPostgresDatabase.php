<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Nightly dump of the production PostgreSQL database, the counterpart of the
 * SQLite backup the other Dot apps run. pg_dump writes a compressed custom
 * format archive (restorable with pg_restore, selectively if need be) to
 * storage/backups, outside the web root. The newest N dated copies are kept.
 *
 * It is a no-op on any other driver, but unlike a silent skip it FAILS
 * (non-zero, message in the scheduler log) when pg_dump is missing or the dump
 * is bad, because a backup that quietly does nothing is worse than none. The
 * password travels in PGPASSWORD, never on the command line, and is scrubbed
 * from any error text. The copy sits beside the live database server on the
 * same host: it covers a bad migration or deletion, not a lost host.
 */
class BackupPostgresDatabase extends Command
{
    protected $signature = 'projects:backup-database
        {--keep=14 : How many dated copies to keep}
        {--pg-dump=pg_dump : The pg_dump binary to run}';

    protected $description = 'Dump the PostgreSQL database to storage/backups with pg_dump, keeping the newest N copies.';

    public function handle(): int
    {
        if (config('database.default') !== 'pgsql') {
            $this->warn('Database connection is not pgsql; nothing to back up.');

            return self::SUCCESS;
        }

        $connection = (array) config('database.connections.pgsql');
        $database = (string) ($connection['database'] ?? '');
        $username = (string) ($connection['username'] ?? '');
        $password = (string) ($connection['password'] ?? '');

        if ($database === '' || $username === '') {
            $this->error('PostgreSQL database or username is not configured.');

            return self::FAILURE;
        }

        $directory = storage_path('backups');

        if (! is_dir($directory) && ! mkdir($directory, 0750, true) && ! is_dir($directory)) {
            $this->error("Cannot create {$directory}.");

            return self::FAILURE;
        }

        $target = $directory.'/projects-'.now()->format('Y-m-d').'.dump';
        $partial = $target.'.partial';
        @unlink($partial);

        $binary = (string) $this->option('pg-dump');
        $process = new Process([
            $binary,
            '--host='.(string) ($connection['host'] ?? '127.0.0.1'),
            '--port='.(string) ($connection['port'] ?? '5432'),
            '--username='.$username,
            '--format=custom',
            '--compress=9',
            '--no-owner',
            '--no-privileges',
            '--file='.$partial,
            $database,
        ], base_path(), $password === '' ? null : ['PGPASSWORD' => $password]);
        $process->setTimeout(600);

        try {
            $process->run();
        } catch (Throwable $e) {
            return $this->failBackup($partial, 'Could not run '.$binary.': '.$e->getMessage(), $password);
        }

        if (! $process->isSuccessful()) {
            return $this->failBackup($partial, "{$binary} exited {$process->getExitCode()}: ".trim($process->getErrorOutput() ?: $process->getOutput() ?: 'no output'), $password);
        }

        if (! is_file($partial) || filesize($partial) === 0) {
            return $this->failBackup($partial, "{$binary} reported success but wrote no data.", $password);
        }

        // A dump that cannot be listed cannot be restored. If pg_restore itself is
        // absent (shell exit 126/127) the dump stays, flagged as unverified.
        $restore = new Process([str_contains($binary, '/') ? dirname($binary).'/pg_restore' : 'pg_restore', '--list', $partial]);
        $restore->setTimeout(120);

        try {
            $restore->run();
            $code = $restore->getExitCode();
        } catch (Throwable) {
            $code = 127;
        }

        if (in_array($code, [126, 127], true)) {
            $this->warn('pg_restore not found; the dump was written but not verified.');
        } elseif ($code !== 0) {
            return $this->failBackup($partial, 'The dump failed pg_restore --list: '.trim($restore->getErrorOutput()), $password);
        }

        if (! rename($partial, $target)) {
            return $this->failBackup($partial, "Could not move the dump to {$target}.", $password);
        }

        chmod($target, 0640);
        $this->prune($directory, max(1, (int) $this->option('keep')));

        $this->info('Backup written to '.$target.' ('.number_format((float) filesize($target)).' bytes)');

        return self::SUCCESS;
    }

    private function failBackup(string $partial, string $message, string $password): int
    {
        @unlink($partial);
        $this->error($password === '' ? $message : str_replace($password, '***', $message));

        return self::FAILURE;
    }

    private function prune(string $directory, int $keep): void
    {
        $files = glob($directory.'/projects-*.dump') ?: [];
        rsort($files);

        foreach (array_slice($files, $keep) as $old) {
            @unlink($old);
        }
    }
}
