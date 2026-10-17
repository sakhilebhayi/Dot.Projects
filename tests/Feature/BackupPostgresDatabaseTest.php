<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Schedule;
use Tests\TestCase;

/**
 * The production database is PostgreSQL, so the nightly copy is a pg_dump.
 * These tests run the real command against small stand-in pg_dump and
 * pg_restore scripts, so they need no database server.
 */
class BackupPostgresDatabaseTest extends TestCase
{
    private string $dir;

    private string $bin;

    /** Generated per run, so no credential-shaped literal lives in the source. */
    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/projects-pg-'.uniqid();
        mkdir($this->dir);
        $this->bin = $this->dir.'/bin';
        mkdir($this->bin);
        $this->app->useStoragePath($this->dir);

        $this->secret = 'pw-'.bin2hex(random_bytes(8));

        config()->set('database.default', 'pgsql');
        config()->set('database.connections.pgsql.host', '10.0.0.5');
        config()->set('database.connections.pgsql.port', '5433');
        config()->set('database.connections.pgsql.database', 'test_database');
        config()->set('database.connections.pgsql.username', 'test_user');
        config()->set('database.connections.pgsql.password', $this->secret);
    }

    /** A stand-in pg_dump that records its arguments and PGPASSWORD, then writes the --file target. */
    private function fakePgDump(string $body = ''): string
    {
        $script = <<<SH
        #!/bin/sh
        printf '%s\\n' "\$@" > {$this->dir}/args.txt
        printf '%s' "\$PGPASSWORD" > {$this->dir}/env.txt
        {$body}
        for a in "\$@"; do case "\$a" in --file=*) out="\${a#--file=}";; esac; done
        printf 'PGDMP-standin' > "\$out"
        SH;
        file_put_contents($this->bin.'/pg_dump', $script."\n");
        chmod($this->bin.'/pg_dump', 0755);

        return $this->bin.'/pg_dump';
    }

    private function fakePgRestore(int $exit): void
    {
        file_put_contents($this->bin.'/pg_restore', "#!/bin/sh\necho 'listing' >&2\nexit {$exit}\n");
        chmod($this->bin.'/pg_restore', 0755);
    }

    public function test_it_writes_a_dated_dump_prunes_old_ones_and_keeps_the_password_off_the_command_line(): void
    {
        $this->fakePgRestore(0);
        $backups = $this->dir.'/backups';
        mkdir($backups);

        foreach (['2020-01-01', '2020-01-02', '2020-01-03'] as $day) {
            touch($backups."/projects-{$day}.dump");
        }

        $this->artisan('projects:backup-database', ['--keep' => 2, '--pg-dump' => $this->fakePgDump()])
            ->expectsOutputToContain('Backup written to')
            ->assertSuccessful();

        $today = $backups.'/projects-'.now()->format('Y-m-d').'.dump';
        $this->assertFileExists($today);
        $this->assertFileDoesNotExist($today.'.partial');

        $remaining = array_map('basename', glob($backups.'/projects-*.dump') ?: []);
        sort($remaining);
        $this->assertSame(['projects-2020-01-03.dump', basename($today)], $remaining);

        $args = (string) file_get_contents($this->dir.'/args.txt');
        $this->assertStringContainsString('--host=10.0.0.5', $args);
        $this->assertStringContainsString('--port=5433', $args);
        $this->assertStringContainsString('--username=test_user', $args);
        $this->assertStringContainsString('--format=custom', $args);
        $this->assertStringContainsString("test_database\n", $args);
        $this->assertStringNotContainsString($this->secret, $args, 'the password must never appear on the command line');
        $this->assertSame($this->secret, file_get_contents($this->dir.'/env.txt'), 'the password travels in PGPASSWORD');
    }

    public function test_a_failing_pg_dump_fails_the_command_scrubs_the_password_and_leaves_no_partial_file(): void
    {
        $this->artisan('projects:backup-database', ['--pg-dump' => $this->fakePgDump('echo "FATAL: auth failed for $PGPASSWORD" >&2; exit 1')])
            ->expectsOutputToContain('***')
            ->assertFailed();

        $this->assertSame([], glob($this->dir.'/backups/*') ?: []);
    }

    public function test_a_missing_pg_dump_is_a_loud_failure_not_a_silent_skip(): void
    {
        $this->artisan('projects:backup-database', ['--pg-dump' => $this->dir.'/no/such/pg_dump'])
            ->expectsOutputToContain('pg_dump')
            ->assertFailed();

        $this->assertSame([], glob($this->dir.'/backups/*') ?: []);
    }

    public function test_a_dump_that_fails_pg_restore_list_is_rejected(): void
    {
        $this->fakePgRestore(1);

        $this->artisan('projects:backup-database', ['--pg-dump' => $this->fakePgDump()])
            ->expectsOutputToContain('pg_restore --list')
            ->assertFailed();

        $this->assertSame([], glob($this->dir.'/backups/*') ?: []);
    }

    public function test_a_missing_pg_restore_keeps_the_dump_but_says_it_was_not_verified(): void
    {
        $this->artisan('projects:backup-database', ['--pg-dump' => $this->fakePgDump()])
            ->expectsOutputToContain('not verified')
            ->assertSuccessful();

        $this->assertCount(1, glob($this->dir.'/backups/projects-*.dump') ?: []);
    }

    public function test_it_does_nothing_on_a_non_pgsql_connection(): void
    {
        config()->set('database.default', 'sqlite');

        $this->artisan('projects:backup-database')
            ->expectsOutputToContain('nothing to back up')
            ->assertSuccessful();
    }

    public function test_it_is_scheduled_nightly_at_0245(): void
    {
        // routes/console.php is only read once a console command has run.
        $this->artisan('schedule:list')->run();

        $events = collect(Schedule::events())->map(fn ($e) => [$e->command, $e->expression]);

        $this->assertTrue($events->contains(fn ($e) => str_contains((string) $e[0], 'projects:backup-database') && $e[1] === '45 2 * * *'));
    }
}
