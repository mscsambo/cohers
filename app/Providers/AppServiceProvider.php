<?php

namespace App\Providers;

use App\Models\Report;
use App\Policies\ReportPolicy;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Admin > Configuration overrides .env (Gemini key/model, Telegram token, targets…).
        \App\Models\SystemSetting::applyToConfig();

        Gate::policy(Report::class, ReportPolicy::class);

        Gate::define('admin', fn ($user) => $user->isActive() && $user->isAdmin());

        Paginator::useTailwind();
    }
}
