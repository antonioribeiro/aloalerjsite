<?php

namespace App\Providers;

use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class ViewComposerServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        View::composer('*', function ($view) {
            if (!isset($view->laravel)) {
                $view->with('laravel', []);
            }

            if (!isset($view->workflow)) {
                $view->with(
                    'workflow',
                    request()->get('workflow') || old('workflow')
                );
            }
        });
    }
}