<?php

use Illuminate\Support\Facades\Route;
use Slyn9\Licensing\Ui\Blade\Http\Controllers\BranchLicenseController;
use Slyn9\Licensing\Ui\Blade\Http\Controllers\LicenseController;

/*
| Solo con licensing.ui.blade = true. Se carga dentro de un grupo con el prefijo, el
| prefijo de nombre y el middleware de config('licensing.routes.*'). Los nombres
| resultantes (admin.licenses.*) son parte del contrato con el anfitrión.
*/

Route::prefix('licenses')
    ->name('licenses.')
    ->controller(LicenseController::class)
    ->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/{company}', 'show')->name('show');
        Route::post('/{company}', 'store')->name('store');
        Route::post('/payment/{license}', 'registerPayment')->name('payment');
        Route::post('/block/{license}', 'block')->name('block');
        Route::post('/unblock/{license}', 'unblock')->name('unblock');
        Route::post('/renew/{license}', 'renew')->name('renew');
        Route::post('/cancel/{license}', 'cancel')->name('cancel');
    });

// Alta de licencia desde la ficha de una sede (modal con respuesta JSON).
if (config('licensing.branches.enabled', false)) {
    Route::post(
        config('licensing.routes.branch_license_uri', 'companies/{company}/branches/{branch}/licenses'),
        [BranchLicenseController::class, 'store']
    )->name(config('licensing.routes.branch_license_name', 'companies.branches.licenses.store'));
}
