<?php

namespace App\Providers;

use App\Services\Integrations\ForwardNoticesToNotify;
use App\Services\ProjectFacts;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Http\Request;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One per request (or job): the stamps it works out for a page's projects are shared by every call on it.
        $this->app->scoped(ProjectFacts::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // /up answered 200 whenever PHP booted, even with the database down. The framework dispatches DiagnosingHealth
        // before it renders the page: a failing query turns the answer into a 500 (the exception text is never shown).
        Event::listen(DiagnosingHealth::class, fn () => DB::select('select 1'));

        // Every in-app notice is also offered to Dot.Notify as an email event (R-34 P11a).
        Event::listen(NotificationSent::class, [ForwardNoticesToNotify::class, 'handle']);

        $this->rateLimits();
    }

    /**
     * Rate limits (R-34 P12a), per signed-in person, or per address for anyone else. Generous enough that nobody
     * working normally meets them; tight enough that a script or a stuck page cannot hammer the server or another
     * business's shared host. A refused request answers 429 with Retry-After.
     */
    private function rateLimits(): void
    {
        $by = fn (Request $request) => $request->user()?->getAuthIdentifier() ?? $request->ip();
        // Every page and form of the signed-in app.
        RateLimiter::for('app', fn (Request $request) => Limit::perMinute(300)->by('app:'.$by($request)));
        // Livewire's round trips: every click and keystroke that talks to the server.
        RateLimiter::for('livewire', fn (Request $request) => Limit::perMinute(600)->by('lw:'.$by($request)));
        // Report files are built in full on each request.
        RateLimiter::for('exports', fn (Request $request) => Limit::perMinute(20)->by('exp:'.$by($request)));
        // Each download is a call to Dot.Files.
        RateLimiter::for('downloads', fn (Request $request) => Limit::perMinute(60)->by('dl:'.$by($request)));

        Livewire::setUpdateRoute(fn ($handle) => Route::post('/livewire/update', $handle)->middleware(['web', 'throttle:livewire'])->name('livewire.update'));
    }
}
