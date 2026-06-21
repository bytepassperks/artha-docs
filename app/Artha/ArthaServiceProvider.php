<?php

declare(strict_types=1);

namespace BookStack\Artha;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/*
| Wires the Artha Business OS single sign-on consumer route into BookStack.
| Kept self-contained in app/Artha (plus one provider line in app/Config/app.php)
| so upstream BookStack updates merge without conflict.
*/
class ArthaServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware('web')->group(function (): void {
            Route::get('/artha/sso/callback', [SsoController::class, 'callback'])
                ->name('artha.sso.callback');
        });
    }
}
