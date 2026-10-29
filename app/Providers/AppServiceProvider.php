<?php

namespace App\Providers;

use App\Modules\Nodes\Models\TenantPbxAssignment;
use App\Modules\Telephony\Models\AuthorizationCode;
use App\Modules\Telephony\Models\CallingProfile;
use App\Modules\Telephony\Models\Extension;
use App\Modules\Telephony\Models\RingGroup;
use App\Modules\Tenancy\Models\ApiToken;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // one per request or job, so a tenant never leaks into the next one on long-running workers
        $this->app->scoped(TenantContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        Relation::morphMap([
            Extension::MORPH => Extension::class,
            RingGroup::MORPH => RingGroup::class,
            TenantPbxAssignment::MORPH => TenantPbxAssignment::class,
            CallingProfile::MORPH => CallingProfile::class,
            AuthorizationCode::MORPH => AuthorizationCode::class,
        ]);

        Sanctum::usePersonalAccessTokenModel(ApiToken::class);

        $this->configureRateLimiting();
        $this->configureDevCommands();
    }

    /**
     * The tenant API: per token, so one client cannot use up another's allowance.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('api', fn (Request $request): Limit => Limit::perMinute(120)->by(
            $request->user()?->currentAccessToken()?->getKey() ?? $request->ip(),
        ));
    }

    /**
     * `php artisan dev` also works the provisioning queue and runs the scheduler, which relays the
     * provisioning outbox every two seconds and syncs the nodes.
     */
    protected function configureDevCommands(): void
    {
        DevCommands::artisan('queue:listen --queue=provisioning,default --tries=1 --timeout=0', 'queue');
        DevCommands::artisan('schedule:work', 'scheduler');
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
