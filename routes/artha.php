<?php

declare(strict_types=1);

use BookStack\Artha\SsoController;
use Illuminate\Support\Facades\Route;

/*
| Artha Business OS — single sign-on consumer route. Isolated in its own file
| (included once from routes/web.php) so upstream BookStack route updates merge
| without conflict. Inherits the same 'web' middleware group as web.php, giving
| the callback the session it needs to log the user in.
*/

Route::get('/artha/sso/callback', [SsoController::class, 'callback'])
    ->name('artha.sso.callback');
