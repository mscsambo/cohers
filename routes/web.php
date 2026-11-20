<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\MfaController;
use App\Http\Controllers\District;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => auth()->check() ? redirect(LoginController::home(auth()->user())) : redirect()->route('login'));

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:20,1');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');
    Route::get('/mfa', [MfaController::class, 'challenge'])->name('mfa.challenge');
    Route::post('/mfa', [MfaController::class, 'verify'])->middleware('throttle:20,1');
    Route::get('/mfa/setup', [MfaController::class, 'setup'])->name('mfa.setup');
    Route::post('/mfa/setup', [MfaController::class, 'enable'])->name('mfa.enable');
});

/*
| Telegram Mini App: Telegram opens /tg and the page signs the person in from
| Telegram's signed initData (no password). Reporters land on "My reports".
*/
Route::get('/tg', [\App\Http\Controllers\MiniApp\MiniAppController::class, 'entry'])->name('miniapp.entry');
Route::post('/tg/auth', [\App\Http\Controllers\MiniApp\MiniAppController::class, 'auth'])->middleware('throttle:30,1')->name('miniapp.auth');

// "My reports": anyone signed in sees only the reports they submitted themselves.
Route::middleware('auth')->prefix('my')->name('my.')->group(function () {
    Route::get('/', [\App\Http\Controllers\MiniApp\MyReportsController::class, 'index'])->name('index');
    Route::get('/new', [\App\Http\Controllers\MiniApp\MyReportsController::class, 'create'])->name('create');
    Route::post('/dictation', [\App\Http\Controllers\MiniApp\MyReportsController::class, 'dictate'])->middleware('throttle:10,1')->name('dictation');
    Route::post('/', [\App\Http\Controllers\MiniApp\MyReportsController::class, 'store'])->middleware('throttle:10,1')->name('store');
    Route::get('/geo/{commune:id}/villages', [\App\Http\Controllers\MiniApp\MyReportsController::class, 'villages'])->name('villages');
    // Reports saved offline on the phone ask for a fresh CSRF token before they are sent.
    Route::get('/sync-token', [\App\Http\Controllers\MiniApp\MyReportsController::class, 'syncToken'])->name('sync-token');
    Route::get('/location', [\App\Http\Controllers\MiniApp\ReporterAccountController::class, 'location'])->name('location');
    Route::post('/location', [\App\Http\Controllers\MiniApp\ReporterAccountController::class, 'updateLocation'])->name('location.update');
    Route::get('/profile', [\App\Http\Controllers\MiniApp\ReporterAccountController::class, 'profile'])->name('profile');
    Route::post('/profile', [\App\Http\Controllers\MiniApp\ReporterAccountController::class, 'updateProfile'])->middleware('throttle:20,1')->name('profile.update');
    Route::get('/photos/{attachment}', [District\ReportController::class, 'attachment'])->name('photo');
    Route::get('/{report}', [\App\Http\Controllers\MiniApp\MyReportsController::class, 'show'])->name('show');
    Route::post('/{report}/translation', [\App\Http\Controllers\MiniApp\MyReportsController::class, 'translate'])->middleware('throttle:10,1')->name('translation');
    Route::post('/{report}/answer', [\App\Http\Controllers\MiniApp\MyReportsController::class, 'answer'])->middleware('throttle:20,1')->name('answer');
    Route::post('/{report}/update', [\App\Http\Controllers\MiniApp\MyReportsController::class, 'addUpdate'])->middleware('throttle:20,1')->name('update');
});

// Self-service Telegram connection and alert preferences for every dashboard user.
Route::middleware(['auth', 'mfa', 'role:district_officer,system_admin,focal_point'])
    ->prefix('account/telegram')->name('account.telegram')->group(function () {
        Route::get('/', [\App\Http\Controllers\AccountController::class, 'telegram']);
        Route::post('/link', [\App\Http\Controllers\AccountController::class, 'link'])->name('.link');
        Route::get('/status', [\App\Http\Controllers\AccountController::class, 'status'])->name('.status');
        Route::post('/unlink', [\App\Http\Controllers\AccountController::class, 'unlink'])->name('.unlink');
        Route::post('/preferences', [\App\Http\Controllers\AccountController::class, 'preferences'])->name('.preferences');
        Route::post('/test', [\App\Http\Controllers\AccountController::class, 'test'])->name('.test');
    });

/*
| Field cases for VHSG / CAO focal points: only cases assigned to them, with the
| same actions as the Telegram buttons.
*/
Route::middleware(['auth', 'mfa', 'role:focal_point'])
    ->prefix('field')->name('field.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Field\FieldCaseController::class, 'index'])->name('index');
        Route::get('/cases/{report}', [\App\Http\Controllers\Field\FieldCaseController::class, 'show'])->name('cases.show');
        Route::post('/cases/{report}/acknowledge', [\App\Http\Controllers\Field\FieldCaseController::class, 'acknowledge'])->name('cases.acknowledge');
        Route::post('/cases/{report}/verify', [\App\Http\Controllers\Field\FieldCaseController::class, 'startVerification'])->name('cases.verify');
        Route::post('/cases/{report}/request-info', [\App\Http\Controllers\Field\FieldCaseController::class, 'requestInfo'])->name('cases.info');
        Route::post('/cases/{report}/escalate', [\App\Http\Controllers\Field\FieldCaseController::class, 'escalate'])->name('cases.escalate');
        Route::post('/cases/{report}/findings', [\App\Http\Controllers\Field\FieldCaseController::class, 'findings'])->name('cases.findings');
    });

// Evidence photos: any dashboard role, then ReportPolicy decides per case.
Route::middleware(['auth', 'mfa', 'role:district_officer,system_admin,focal_point'])
    ->get('/attachments/{attachment}', [District\ReportController::class, 'attachment'])->name('district.attachments.show');

// Old /district/... links (bookmarks, Telegram messages) move to the same page without the prefix.
Route::get('/district/{path?}', function (Illuminate\Http\Request $request, ?string $path = null) {
    $query = $request->getQueryString();

    return redirect('/'.($path ?: 'dashboard').($query ? '?'.$query : ''), 301);
})->where('path', '.*');

/*
| Case portal (reports, escalations, map…). Admins may open it in monitoring
| mode (all areas); the ReportPolicy restricts what each role may change.
| Route names keep the "district." prefix; the URLs have none.
*/
Route::middleware(['auth', 'mfa', 'role:district_officer,system_admin'])
    ->name('district.')->group(function () {
        Route::get('/dashboard', [District\DashboardController::class, 'overview'])->name('overview');
        Route::get('/reports', [District\ReportController::class, 'index'])->name('reports.index');
        Route::get('/reports/export', [District\ReportController::class, 'export'])->name('reports.export');
        Route::get('/reports/{report}', [District\ReportController::class, 'show'])->name('reports.show');
        Route::get('/reports/{report}/drawer', [District\ReportController::class, 'drawer'])->name('reports.drawer');
        Route::post('/reports/{report}/reassign', [District\ReportController::class, 'reassign'])->name('reports.reassign');
        Route::post('/reports/{report}/triage', [District\ReportController::class, 'triage'])->name('reports.triage');
        Route::post('/reports/{report}/link', [District\ReportController::class, 'linkIncident'])->name('reports.link');
        Route::post('/reports/{report}/close', [District\ReportController::class, 'close'])->name('reports.close');
        Route::post('/reports/{report}/reopen', [District\ReportController::class, 'reopen'])->name('reports.reopen');

        Route::get('/escalations', [District\EscalationController::class, 'index'])->name('escalations.index');
        Route::post('/escalations/{escalation}/review', [District\EscalationController::class, 'review'])->name('escalations.review');
        Route::post('/escalations/{escalation}/close', [District\EscalationController::class, 'resolve'])->name('escalations.resolve');

        Route::get('/workload', [District\DashboardController::class, 'workload'])->name('workload');
        Route::get('/map', [District\DashboardController::class, 'map'])->name('map');
        Route::get('/map/data', [District\DashboardController::class, 'mapData'])->name('map.data');
        Route::get('/analytics', [District\DashboardController::class, 'analytics'])->name('analytics');
        Route::get('/closures', [District\DashboardController::class, 'closures'])->name('closures');
        Route::get('/geo/{district}/communes', [District\DashboardController::class, 'communes'])->name('geo.communes');
    });

Route::middleware(['auth', 'mfa', 'role:system_admin'])
    ->prefix('admin')->name('admin.')->group(function () {
        Route::get('/', Admin\DashboardController::class)->name('overview');

        Route::get('/users', [Admin\UserController::class, 'index'])->name('users.index');
        Route::get('/users/create', [Admin\UserController::class, 'create'])->name('users.create');
        Route::post('/users', [Admin\UserController::class, 'store'])->name('users.store');
        Route::get('/users/{user}', [Admin\UserController::class, 'edit'])->name('users.edit');
        Route::put('/users/{user}', [Admin\UserController::class, 'update'])->name('users.update');
        Route::post('/users/{user}/link-token', [Admin\UserController::class, 'linkToken'])->name('users.link');
        Route::post('/users/{user}/unlink', [Admin\UserController::class, 'unlinkTelegram'])->name('users.unlink');
        Route::post('/users/{user}/reset-mfa', [Admin\UserController::class, 'resetMfa'])->name('users.reset-mfa');

        Route::get('/geography', [Admin\GeographyController::class, 'index'])->name('geography.index');
        Route::post('/geography', [Admin\GeographyController::class, 'store'])->name('geography.store');
        Route::put('/geography/{unit}', [Admin\GeographyController::class, 'update'])->name('geography.update');
        Route::post('/geography-import', [Admin\GeographyController::class, 'importUpload'])->name('geography.import.upload');
        Route::post('/geography-import/apply', [Admin\GeographyController::class, 'importApply'])->name('geography.import.apply');
        Route::post('/geography-import/cancel', [Admin\GeographyController::class, 'importCancel'])->name('geography.import.cancel');
        Route::get('/geography-import/template', [Admin\GeographyController::class, 'importTemplate'])->name('geography.import.template');

        Route::get('/routing', [Admin\RoutingController::class, 'index'])->name('routing.index');
        Route::post('/routing/mappings/{mapping}/deactivate', [Admin\RoutingController::class, 'deactivateMapping'])->name('routing.mappings.deactivate');
        Route::post('/routing/rules', [Admin\RoutingController::class, 'storeRule'])->name('routing.rules.store');
        Route::post('/routing/rules/{rule}/toggle', [Admin\RoutingController::class, 'toggleRule'])->name('routing.rules.toggle');

        Route::get('/categories', [Admin\CategoryController::class, 'index'])->name('categories.index');
        Route::post('/categories', [Admin\CategoryController::class, 'store'])->name('categories.store');
        Route::put('/categories/{category}', [Admin\CategoryController::class, 'update'])->name('categories.update');
        Route::post('/ai/test', [Admin\CategoryController::class, 'test'])->name('ai.test');

        Route::get('/telegram-users', [Admin\TelegramUserController::class, 'index'])->name('telegram-users.index');
        Route::get('/telegram-users/export', [Admin\TelegramUserController::class, 'export'])->name('telegram-users.export');

        Route::get('/configuration', [Admin\ConfigurationController::class, 'edit'])->name('configuration.edit');
        Route::put('/configuration', [Admin\ConfigurationController::class, 'update'])->name('configuration.update');
        Route::post('/configuration/test-gemini', [Admin\ConfigurationController::class, 'testGemini'])->name('configuration.test-gemini');
        Route::get('/configuration/gemini-models', [Admin\ConfigurationController::class, 'geminiModels'])->name('configuration.gemini-models');
        Route::post('/configuration/test-telegram', [Admin\ConfigurationController::class, 'testTelegram'])->name('configuration.test-telegram');
        Route::post('/configuration/webhook', [Admin\ConfigurationController::class, 'webhook'])->name('configuration.webhook');

        Route::get('/jobs', [Admin\JobsController::class, 'index'])->name('jobs.index');
        Route::post('/jobs/notifications/{log}/retry', [Admin\JobsController::class, 'retryNotification'])->name('jobs.notification.retry');
        Route::post('/jobs/failed/{uuid}/retry', [Admin\JobsController::class, 'retryJob'])->name('jobs.failed.retry');
        Route::post('/jobs/failed/{uuid}/forget', [Admin\JobsController::class, 'forgetJob'])->name('jobs.failed.forget');
        Route::post('/jobs/overdue', [Admin\JobsController::class, 'runOverdue'])->name('jobs.overdue');

        Route::get('/audit', [Admin\AuditController::class, 'index'])->name('audit.index');
        Route::get('/audit/export', [Admin\AuditController::class, 'export'])->name('audit.export');

        Route::get('/settings', [Admin\SettingsController::class, 'edit'])->name('settings.edit');
        Route::put('/settings', [Admin\SettingsController::class, 'update'])->name('settings.update');
    });
