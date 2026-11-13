<?php

use App\Http\Controllers\ApiDocsController;
use App\Http\Controllers\PaymentReturnController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
| After Paystack checkout the browser hits GET /api/callbacks/paystack,
| which verifies the payment then redirects here with ?reference=&status=.
*/
Route::get('payments/complete', [PaymentReturnController::class, 'complete'])
    ->name('payments.complete');

/*
| Interactive API playbook for the frontend team (payloads + live/example responses).
| Recapture fixtures: php resources/api-docs/capture-fixtures.php (with artisan serve).
*/
Route::get('docs/api', [ApiDocsController::class, 'index'])->name('docs.api');
