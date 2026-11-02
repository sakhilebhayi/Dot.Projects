<?php

use App\Http\Controllers\Auth\EcosystemAuthController;
use App\Http\Controllers\CspReportController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GovernanceController;
use App\Http\Controllers\GuardianHealthController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectTimeController;
use App\Http\Controllers\SchedulerProbeController;
use App\Http\Middleware\AuthenticateGuardian;
use App\Models\Project;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Laravel\Fortify\Features;
use Laravel\Jetstream\Jetstream;

Route::post('/auth/ecosystem', [EcosystemAuthController::class, 'handle'])
    ->withoutMiddleware(['Illuminate\Foundation\Http\Middleware\PreventRequestForgery', 'Illuminate\Foundation\Http\Middleware\VerifyCsrfToken', 'Illuminate\Foundation\Http\Middleware\ValidateCsrfToken'])
    ->name('ecosystem.auth');

// Links minted before the token moved into a POST body carried it in the URL. They are no longer honoured:
// the visitor is sent to the normal sign-in page and the token in the URL is never read.
Route::get('/auth/ecosystem', [EcosystemAuthController::class, 'legacy']);

// Content-Security-Policy violation reports (the policy is report-only). The browser posts these by itself, so there is
// no session, no cookie, no CSRF token and no user; the controller keeps only the directive and the blocked host.
Route::post('/csp-report', CspReportController::class)
    ->middleware('throttle:60,1,csp-report')
    ->withoutMiddleware([
        EncryptCookies::class,
        AddQueuedCookiesToResponse::class,
        StartSession::class,
        ShareErrorsFromSession::class,
        PreventRequestForgery::class,
    ])
    ->name('csp.report');

Route::get('/', fn () => view('welcome'));

// Cookie Policy — Jetstream's termsAndPrivacyPolicy feature covers terms.show/policy.show
// natively (registered at /terms-of-service and /privacy-policy, reading resources/markdown/
// terms.md and policy.md). There's no Jetstream equivalent for a Cookie Policy, so this one is
// wired by hand, following the exact same Markdown-source convention.
Route::get('/cookies', function () {
    return view('cookies', [
        'cookies' => Str::markdown(file_get_contents(Jetstream::localizedMarkdownPath('cookies.md'))),
    ]);
})->name('cookies');

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::view('/time', 'time.index')->name('time.index');

    Route::get('/projects/create', fn () => view('projects.create'))
        ->middleware('can:create,'.Project::class)
        ->name('projects.create');
    Route::get('/projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
    Route::get('/projects/{project}/board', [ProjectController::class, 'board'])->name('projects.board');
    Route::get('/projects/{project}/work', [ProjectController::class, 'work'])->name('projects.work');
    Route::get('/projects/{project}/schedule', [ProjectController::class, 'schedule'])->name('projects.schedule');
    Route::get('/projects/{project}/time', [ProjectTimeController::class, 'show'])->name('projects.time');
    Route::get('/projects/{project}/time.csv', [ProjectTimeController::class, 'csv'])->name('projects.time.csv');
    Route::get('/projects/{project}/risks', [GovernanceController::class, 'risks'])->name('projects.risks');
    Route::get('/projects/{project}/risks/{risk}', [GovernanceController::class, 'risk'])->scopeBindings()->name('projects.risks.show');
    Route::get('/projects/{project}/issues', [GovernanceController::class, 'issues'])->name('projects.issues');
    Route::get('/projects/{project}/issues/{issue}', [GovernanceController::class, 'issue'])->scopeBindings()->name('projects.issues.show');
    Route::get('/projects/{project}/changes', [GovernanceController::class, 'changes'])->name('projects.changes');
    Route::get('/projects/{project}/changes/{change}', [GovernanceController::class, 'change'])->scopeBindings()->name('projects.changes.show');
    Route::get('/projects/{project}/decisions', [GovernanceController::class, 'decisions'])->name('projects.decisions');
    Route::get('/projects/{project}/decisions/{decision}', [GovernanceController::class, 'decision'])->scopeBindings()->name('projects.decisions.show');
    Route::get('/projects/{project}/gates', [GovernanceController::class, 'gates'])->name('projects.gates');
    Route::get('/projects/{project}/gates/{gate}', [GovernanceController::class, 'gate'])->scopeBindings()->name('projects.gates.show');
    Route::get('/projects/{project}/meetings', [GovernanceController::class, 'meetings'])->name('projects.meetings');
    Route::get('/projects/{project}/meetings/{meeting}', [GovernanceController::class, 'meeting'])->scopeBindings()->name('projects.meetings.show');
    Route::get('/projects/{project}/actions', [GovernanceController::class, 'actions'])->name('projects.actions');
    Route::get('/projects/{project}/reports', [GovernanceController::class, 'reports'])->name('projects.reports');
    Route::get('/projects/{project}/reports/{report}', [GovernanceController::class, 'report'])->scopeBindings()->name('projects.reports.show');
    Route::get('/projects/{project}/settings', [ProjectController::class, 'settings'])->name('projects.settings');
    Route::get('/projects/{project}/tasks/{task}', [ProjectController::class, 'task'])->scopeBindings()->name('projects.tasks.show');
});

// Public liveness probe for the cron scheduler, watched from GitHub every
// 15 minutes by .github/workflows/scheduler-watchdog.yml.
Route::get('/up/scheduler', SchedulerProbeController::class)->name('up.scheduler');

// Guardian observability report for Dot.Brain (dot-guardian/v1): operational
// detail, so behind a bearer token (fails closed with 503 when none is
// configured) rather than public like /up and /up/scheduler.
Route::get('/guardian/health', GuardianHealthController::class)
    ->middleware(AuthenticateGuardian::class)
    ->name('guardian.health');

// Public sign-up is closed (accounts are created by InfoDot at first sign-in). Keep the named route so any link or
// invitation email that points at it lands on the login page instead of failing.
if (! Features::enabled(Features::registration())) {
    Route::redirect('/register', '/login')->name('register');
}
