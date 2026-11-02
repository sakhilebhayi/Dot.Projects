<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** R-34 P12a: rate limits per person on the app, report files, downloads and Livewire round trips. */
class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_files_are_limited_per_person_and_answer_429_with_retry_after(): void
    {
        $me = User::factory()->withPersonalTeam()->create();
        $other = User::factory()->withPersonalTeam()->create();
        $url = route('reporting.export', ['format' => 'csv', 'type' => 'risks']);
        foreach (range(1, 20) as $i) {
            $this->actingAs($me)->get($url)->assertOk();
        }
        $this->actingAs($me)->get($url)->assertStatus(429)->assertHeader('Retry-After');
        $this->actingAs($other)->get($url)->assertOk(); // limits are per person
    }

    public function test_every_part_of_the_app_has_its_limit(): void
    {
        $limits = fn (string $name) => collect(Route::getRoutes()->getByName($name)?->gatherMiddleware() ?? [])->filter(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:'))->values()->all();
        $this->assertContains('throttle:app', $limits('dashboard'));
        $this->assertContains('throttle:app', $limits('projects.show'));
        $this->assertContains('throttle:exports', $limits('reporting.export'));
        $this->assertContains('throttle:downloads', $limits('projects.documents.download'));
        $this->assertContains('throttle:livewire', $limits('livewire.update'));
    }
}
