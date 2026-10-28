<?php

use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\AdminProfileController;
use App\Http\Controllers\Admin\OnboardingController;
use App\Http\Controllers\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Admin\PaymentController as AdminPaymentController;
use App\Http\Controllers\Admin\PermissionController;
use App\Http\Controllers\Admin\PlanController as AdminPlanController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SenderIdController as AdminSenderIdController;
use App\Http\Controllers\Admin\SubscriptionController as AdminSubscriptionController;
use App\Http\Controllers\Admin\WalletController as AdminWalletController;
use App\Http\Controllers\Auth\AdminAuthController;
use App\Http\Controllers\Auth\ClientAuthController;
use App\Http\Controllers\Client\ClientActivityLogController;
use App\Http\Controllers\Client\ClientProfileController;
use App\Http\Controllers\Client\OrderController as ClientOrderController;
use App\Http\Controllers\Client\PaymentController as ClientPaymentController;
use App\Http\Controllers\Client\PlanController as ClientPlanController;
use App\Http\Controllers\Client\SenderIdController as ClientSenderIdController;
use App\Http\Controllers\Client\SubscriptionController as ClientSubscriptionController;
use App\Http\Controllers\Client\WalletController as ClientWalletController;
use Illuminate\Support\Facades\Route;

Route::prefix('client')->group(function () {
    Route::post('signup', [ClientAuthController::class, 'signup']);
    Route::middleware('throttle:5,1')->group(function () {
        Route::post('request_otp', [ClientAuthController::class, 'requestOtp']);
        Route::post('resend_otp_request', [ClientAuthController::class, 'resendOtpRequest']);
        Route::post('verify_otp', [ClientAuthController::class, 'verifyOtp']);
    });

    Route::get('plans', [ClientPlanController::class, 'index']);

    Route::middleware(['auth:client', 'account.active'])->group(function () {
        Route::get('profile', [ClientProfileController::class, 'profile']);
        Route::post('profile', [ClientProfileController::class, 'updateProfile']);
        Route::put('profile', [ClientProfileController::class, 'updateProfile']);
        Route::get('activity-logs', [ClientActivityLogController::class, 'index']);
        Route::post('logout', [ClientAuthController::class, 'logout']);

        Route::get('wallet', [ClientWalletController::class, 'show']);
        Route::get('wallet/top-ups', [ClientWalletController::class, 'topUps']);
        Route::post('wallet/top-ups', [ClientWalletController::class, 'storeTopUp']);

        Route::get('subscription', [ClientSubscriptionController::class, 'show']);
        Route::get('subscription/archives', [ClientSubscriptionController::class, 'archives']);
        Route::post('subscription', [ClientSubscriptionController::class, 'store']);

        Route::get('sender-ids', [ClientSenderIdController::class, 'index']);
        Route::post('sender-ids', [ClientSenderIdController::class, 'store']);
        Route::get('sender-ids/{senderId}', [ClientSenderIdController::class, 'show']);

        Route::get('payments', [ClientPaymentController::class, 'index']);
        Route::get('payments/{payment}', [ClientPaymentController::class, 'show']);

        Route::get('orders', [ClientOrderController::class, 'index']);
        Route::post('orders', [ClientOrderController::class, 'store']);
        Route::get('orders/{order}', [ClientOrderController::class, 'show']);
    });
});

Route::prefix('admin')->group(function () {
    Route::middleware('throttle:5,1')->group(function () {
        Route::post('request_otp', [AdminAuthController::class, 'requestOtp']);
        Route::post('resend_otp_request', [AdminAuthController::class, 'resendOtpRequest']);
        Route::post('verify_otp', [AdminAuthController::class, 'verifyOtp']);
    });

    Route::middleware(['auth:admin', 'account.active'])->group(function () {
        Route::get('profile', [AdminProfileController::class, 'profile']);
        Route::post('profile', [AdminProfileController::class, 'updateProfile']);
        Route::put('profile', [AdminProfileController::class, 'updateProfile']);
        Route::post('logout', [AdminAuthController::class, 'logout']);

        Route::post('onboard/client', [OnboardingController::class, 'client']);
        Route::post('onboard/admin', [OnboardingController::class, 'admin']);

        Route::get('roles', [RoleController::class, 'index']);
        Route::post('roles', [RoleController::class, 'store']);
        Route::get('roles/{role}', [RoleController::class, 'show']);
        Route::put('roles/{role}', [RoleController::class, 'update']);
        Route::post('roles/assign', [RoleController::class, 'assign']);

        Route::get('permissions', [PermissionController::class, 'index']);

        Route::get('activity-logs', [ActivityLogController::class, 'index']);
        Route::get('clients/{client}/activity-logs', [ActivityLogController::class, 'forClient']);

        Route::get('plans', [AdminPlanController::class, 'index']);
        Route::post('plans', [AdminPlanController::class, 'store']);
        Route::get('plans/{plan}', [AdminPlanController::class, 'show']);
        Route::put('plans/{plan}', [AdminPlanController::class, 'update']);

        Route::get('subscriptions', [AdminSubscriptionController::class, 'index']);
        Route::get('subscription-archives', [AdminSubscriptionController::class, 'archives']);
        Route::get('clients/{client}/subscription', [AdminSubscriptionController::class, 'forClient']);
        Route::post('clients/{client}/subscription', [AdminSubscriptionController::class, 'assign']);

        Route::get('wallets', [AdminWalletController::class, 'index']);
        Route::get('wallet-top-ups', [AdminWalletController::class, 'topUps']);
        Route::get('clients/{client}/wallet', [AdminWalletController::class, 'forClient']);

        Route::get('sender-ids', [AdminSenderIdController::class, 'index']);
        Route::get('sender-ids/{senderId}', [AdminSenderIdController::class, 'show']);
        Route::post('sender-ids/{senderId}/approve', [AdminSenderIdController::class, 'approve']);
        Route::post('sender-ids/{senderId}/reject', [AdminSenderIdController::class, 'reject']);

        Route::get('payments', [AdminPaymentController::class, 'index']);
        Route::get('payments/{payment}', [AdminPaymentController::class, 'show']);
        Route::post('payments/{payment}/complete', [AdminPaymentController::class, 'complete']);
        Route::post('payments/{payment}/fail', [AdminPaymentController::class, 'fail']);

        Route::get('orders', [AdminOrderController::class, 'index']);
        Route::get('orders/{order}', [AdminOrderController::class, 'show']);
    });
});
