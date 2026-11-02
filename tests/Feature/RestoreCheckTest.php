<?php

namespace Tests\Feature;

use App\Models\IntegrationDelivery;
use Database\Seeders\LoadSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/** R-34 P12c: the restore drill's check, and the synthetic seeder's refusal to run in production. */
class RestoreCheckTest extends TestCase
{
    use RefreshDatabase;

    private function delivery(int $i): IntegrationDelivery
    {
        return IntegrationDelivery::query()->forceCreate(['integration' => 'notify', 'event' => 'projects.drill', 'key' => "d{$i}", 'payload' => ['n' => $i], 'status' => 'sent']);
    }

    public function test_a_complete_copy_with_readable_values_passes(): void
    {
        $this->delivery(1);
        $this->delivery(2);

        $this->artisan('projects:verify-restore', ['--connection' => 'sqlite', '--against' => 'sqlite'])
            ->expectsOutputToContain('migrations applied')
            ->expectsOutputToContain('every count matches')
            ->expectsOutputToContain('2 sampled values decrypt')
            ->expectsOutputToContain('Restore check passed')
            ->assertSuccessful();
    }

    public function test_values_that_do_not_decrypt_with_this_key_fail(): void
    {
        $this->delivery(1);
        DB::table('integration_deliveries')->update(['payload' => 'written-with-another-key']);

        $this->artisan('projects:verify-restore', ['--connection' => 'sqlite'])
            ->expectsOutputToContain('do NOT decrypt')
            ->assertFailed();
    }

    public function test_a_copy_missing_a_migration_fails(): void
    {
        DB::table('migrations')->where('migration', '2026_12_05_100000_create_retention_policies')->delete();

        $this->artisan('projects:verify-restore', ['--connection' => 'sqlite'])
            ->expectsOutputToContain('not applied on the copy')
            ->assertFailed();
    }

    public function test_the_load_seeder_refuses_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->expectException(RuntimeException::class);
        (new LoadSeeder)->run();
    }
}
