<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
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
        $this->forceHttpsInProduction();
        $this->healthCheck();
        $this->passwordResetUrl();
        $this->rateLimiters();
    }

    private function forceHttpsInProduction(): void
    {
        if (! $this->app->isProduction()) {
            return;
        }

        URL::forceScheme('https');
        // Secure cookies must be explicit: behind a proxy Laravel cannot always
        // tell that the request arrived over TLS.
        config(['session.secure' => true]);
    }

    /**
     * `/up` only proves PHP booted. Make it fail when MySQL is unreachable so
     * uptime monitors and the deploy smoke test notice.
     */
    private function healthCheck(): void
    {
        Event::listen(DiagnosingHealth::class, function (): void {
            DB::connection()->getPdo()->query('select 1');
        });
    }

    private function passwordResetUrl(): void
    {
        ResetPassword::createUrlUsing(function (User $user, string $token): string {
            return rtrim((string) config('app.url'), '/')
                .'/login?mode=reset&token='.urlencode($token)
                .'&email='.urlencode($user->email);
        });
    }

    private function rateLimiters(): void
    {
        $byUser = fn (Request $request) => $request->user()?->id ?: $request->ip();

        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));
        RateLimiter::for('messages', fn (Request $request) => Limit::perMinute(120)->by($byUser($request)));
        RateLimiter::for('attachments', fn (Request $request) => Limit::perMinute(180)->by($byUser($request)));
        RateLimiter::for('uploads', fn (Request $request) => Limit::perMinute(20)->by($byUser($request)));
        RateLimiter::for('search', fn (Request $request) => Limit::perMinute(60)->by($byUser($request)));
        RateLimiter::for('exports', fn (Request $request) => Limit::perMinute(5)->by($byUser($request)));
        RateLimiter::for('presence', fn (Request $request) => Limit::perMinute(10)->by($byUser($request)));
        // Push has its own buckets: sharing `presence` with heartbeats and key
        // backups meant a phone's token could be refused at sign-in, and the
        // test button said "Too many attempts" before it was ever pressed.
        RateLimiter::for('push', fn (Request $request) => Limit::perMinute(30)->by($byUser($request)));
        RateLimiter::for('push-test', fn (Request $request) => Limit::perMinute(6)->by($byUser($request)));
        RateLimiter::for('calls', fn (Request $request) => Limit::perMinute(60)->by($byUser($request)));
        // Signal polling alone is ~90 a minute, plus ICE candidates for every
        // peer in a group call. A dropped answer leaves audio one-way.
        RateLimiter::for('call-signals', fn (Request $request) => Limit::perMinute(900)->by($byUser($request)));
    }
}
