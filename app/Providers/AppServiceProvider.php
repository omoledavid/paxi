<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Schema;
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
        Schema::defaultStringLength(191);
        ResetPassword::createUrlUsing(function (object $notifiable, string $token) {
            return config('app.frontend_url') . "/password-reset/$token?email={$notifiable->getEmailForPasswordReset()}";
        });
        if (App::environment('local')) {
            $this->app['config']->set('app.debug', true);
        }

        try{
            if(Schema::hasTable('apiconfigs')){
                config([
                    'nellobytes.base_url' => getConfigValue(\App\Models\ApiConfig::all(), 'nellobytesBaseUrl'),
                    'nellobytes.user_id' => getConfigValue(\App\Models\ApiConfig::all(), 'nellobytesUserId'),
                    'nellobytes.api_key' => getConfigValue(\App\Models\ApiConfig::all(), 'nellobytesApi'),
                ]);
            }

        }catch(\Exception $e){
            //log error
        }

        // Force HTTPS in production
        if (App::environment('production')) {
            URL::forceScheme('https');
        }
    }
}
