<?php

namespace App\Providers;

use App\Services\Integrations\ForwardNoticesToNotify;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
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
    }
}
