<?php

use App\Http\Controllers\Api\V1\Admin\AdminProfileController;
use App\Http\Controllers\Api\V1\Auth\AdminAuthController;
use App\Http\Controllers\Api\V1\Auth\ClientAuthController;
use App\Http\Controllers\Api\V1\Client\ClientProfileController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Clients API Routes
    Route::prefix('client')->group(function () {
        Route::middleware('auth:client')->group(function () {
            // unauthenticated routes
            Route::post('request_otp', [ClientAuthController::class, 'requestOtp']);
            Route::post('resend_otp_request', [ClientAuthController::class, 'resendOtpRequest']);
            Route::post('verify_otp', [ClientAuthController::class, 'verifyOtp']);

            // authenticated routes
            // get user profile
            Route::get('profile', [ClientProfileController::class, 'profile']);
            // update profile
            Route::put('profile', [ClientProfileController::class, 'updateProfile']);
        });
    });

    // Admin API Routes
    Route::prefix('admin')->group(function () {
        Route::middleware('auth:admin')->group(function () {
            // unauthenticated routes
            Route::post('request_otp', [AdminAuthController::class, 'requestOtp']);
            Route::post('resend_otp_request', [AdminAuthController::class, 'resendOtpRequest']);
            Route::post('verify_otp', [AdminAuthController::class, 'verifyOtp']);

            // authenticated routes
            // get admin profile
            Route::get('profile', [AdminProfileController::class, 'profile']);
            // update admin profile
            Route::put('profile', [AdminProfileController::class, 'updateProfile']);
        });
    });
});
