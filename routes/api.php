<?php

use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\AdminProfileController;
use App\Http\Controllers\Admin\ClientController as AdminClientController;
use App\Http\Controllers\Admin\MarginController as AdminMarginController;
use App\Http\Controllers\Admin\OnboardingController;
use App\Http\Controllers\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Admin\PaymentController as AdminPaymentController;
use App\Http\Controllers\Admin\PaymentGatewayController as AdminPaymentGatewayController;
use App\Http\Controllers\Admin\PermissionController;
use App\Http\Controllers\Admin\PlanController as AdminPlanController;
use App\Http\Controllers\Admin\ReportController as AdminReportController;
use App\Http\Controllers\Admin\RewardController as AdminRewardController;
use App\Http\Controllers\Admin\RewardDisbursementController as AdminRewardDisbursementController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SenderIdBlacklistController as AdminSenderIdBlacklistController;
use App\Http\Controllers\Admin\SenderIdController as AdminSenderIdController;
use App\Http\Controllers\Admin\SmsController as AdminSmsController;
use App\Http\Controllers\Admin\SubscriptionController as AdminSubscriptionController;
use App\Http\Controllers\Admin\UtilityController as AdminUtilityController;
use App\Http\Controllers\Admin\WalletController as AdminWalletController;
use App\Http\Controllers\Auth\AdminAuthController;
use App\Http\Controllers\Auth\ClientAuthController;
use App\Http\Controllers\Client\ApiKeyController as ClientApiKeyController;
use App\Http\Controllers\Client\CampaignController as ClientCampaignController;
use App\Http\Controllers\Client\ClientActivityLogController;
use App\Http\Controllers\Client\ClientProfileController;
use App\Http\Controllers\Client\ContactController as ClientContactController;
use App\Http\Controllers\Client\ContactGroupController as ClientContactGroupController;
use App\Http\Controllers\Client\OrderController as ClientOrderController;
use App\Http\Controllers\Client\PaymentController as ClientPaymentController;
use App\Http\Controllers\Client\PlanController as ClientPlanController;
use App\Http\Controllers\Client\ReportController as ClientReportController;
use App\Http\Controllers\Client\RewardController as ClientRewardController;
use App\Http\Controllers\Client\RewardDisbursementController as ClientRewardDisbursementController;
use App\Http\Controllers\Client\SenderIdController as ClientSenderIdController;
use App\Http\Controllers\Client\SmsController as ClientSmsController;
use App\Http\Controllers\Client\SubscriptionController as ClientSubscriptionController;
use App\Http\Controllers\Client\SurveyController as ClientSurveyController;
use App\Http\Controllers\Client\UtilityController as ClientUtilityController;
use App\Http\Controllers\Client\WalletController as ClientWalletController;
use App\Http\Controllers\PaymentWebhookController;
use App\Http\Controllers\PublicApi\CampaignController as PublicCampaignController;
use App\Http\Controllers\PublicApi\SurveyController as PublicSurveyController;
use App\Http\Controllers\SwiftWebhookController;
use Illuminate\Support\Facades\Route;

/*
| Payment gateway notifications (public, verified by signature/token, then re-checked with the gateway).
*/
Route::middleware('throttle:120,1')->group(function () {
    Route::post('webhooks/paystack', [PaymentWebhookController::class, 'paystack']);
    Route::get('callbacks/paystack', [PaymentWebhookController::class, 'paystackReturn']);
    Route::post('webhooks/kairos-payment', [PaymentWebhookController::class, 'kairos']);

    // Swift order updates (order.fulfilled / order.failed), signed with SWIFT_SECRET_KEY when set.
    Route::post('swift_webhook', [SwiftWebhookController::class, 'handle']);
});

/*
| Public pages for people who receive a campaign or survey SMS. The campaign id / survey token
| is the only credential, so these are rate limited per IP.
*/
Route::prefix('public')->middleware('throttle:60,1')->group(function () {
    Route::get('surveys/{token}', [PublicSurveyController::class, 'show']);
    Route::post('surveys/{token}/click', [PublicSurveyController::class, 'click']);
    Route::post('surveys/{token}/submit', [PublicSurveyController::class, 'submit']);
    Route::post('campaigns/{campaign}/click', [PublicCampaignController::class, 'click'])->whereNumber('campaign');
});

Route::prefix('client')->group(function () {
    Route::post('signup', [ClientAuthController::class, 'signup'])->middleware('throttle:5,1');
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
        Route::post('wallet/top-ups', [ClientWalletController::class, 'storeTopUp'])->middleware('throttle:checkout');
        Route::get('wallet/transactions', [ClientWalletController::class, 'transactions']);

        Route::get('subscription', [ClientSubscriptionController::class, 'show']);
        Route::get('subscription/archives', [ClientSubscriptionController::class, 'archives']);
        Route::post('subscription', [ClientSubscriptionController::class, 'store'])->middleware('throttle:checkout');

        Route::get('sender-ids', [ClientSenderIdController::class, 'index']);
        Route::post('sender-ids', [ClientSenderIdController::class, 'store']);
        Route::get('sender-ids/{senderId}', [ClientSenderIdController::class, 'show']);
        Route::put('sender-ids/{senderId}', [ClientSenderIdController::class, 'update']);
        Route::delete('sender-ids/{senderId}', [ClientSenderIdController::class, 'destroy']);

        Route::get('contact-groups/stats', [ClientContactGroupController::class, 'stats']);
        Route::get('contact-groups', [ClientContactGroupController::class, 'index']);
        Route::get('contact-groups/trashed', [ClientContactGroupController::class, 'trashed']);
        Route::post('contact-groups', [ClientContactGroupController::class, 'store']);
        Route::whereNumber(['contactGroup', 'contact'])->group(function () {
            Route::get('contact-groups/{contactGroup}', [ClientContactGroupController::class, 'show']);
            Route::put('contact-groups/{contactGroup}', [ClientContactGroupController::class, 'update']);
            Route::delete('contact-groups/{contactGroup}', [ClientContactGroupController::class, 'destroy']);
            Route::post('contact-groups/{contactGroup}/restore', [ClientContactGroupController::class, 'restore']);
            Route::delete('contact-groups/{contactGroup}/force', [ClientContactGroupController::class, 'forceDestroy']);

            Route::post('contact-groups/{contactGroup}/verify-beneficiaries', [ClientContactController::class, 'verifyBeneficiaries'])->middleware('throttle:10,1');
            Route::post('contact-groups/{contactGroup}/submit-beneficiaries', [ClientContactController::class, 'submitBeneficiaries'])->middleware('throttle:10,1');
            Route::get('contact-groups/{contactGroup}/contacts', [ClientContactController::class, 'index']);
            Route::post('contact-groups/{contactGroup}/contacts', [ClientContactController::class, 'store']);
            Route::post('contact-groups/{contactGroup}/contacts/upload', [ClientContactController::class, 'upload'])->middleware('throttle:10,1');
            Route::get('contact-groups/{contactGroup}/contacts/upload', [ClientContactController::class, 'uploads']);
            Route::get('contact-groups/{contactGroup}/contacts/upload/{import}/contacts', [ClientContactController::class, 'uploadContacts']);
            Route::get('contact-groups/{contactGroup}/contacts/upload/{import}', [ClientContactController::class, 'uploadStatus']);
            Route::delete('contact-groups/{contactGroup}/contacts/upload/{import}', [ClientContactController::class, 'destroyUpload']);
            Route::get('contact-groups/{contactGroup}/contacts/{contact}', [ClientContactController::class, 'show']);
            Route::put('contact-groups/{contactGroup}/contacts/{contact}', [ClientContactController::class, 'update']);
            Route::patch('contact-groups/{contactGroup}/contacts/{contact}/dnd', [ClientContactController::class, 'dnd']);
            Route::delete('contact-groups/{contactGroup}/contacts/{contact}', [ClientContactController::class, 'destroy']);
        });

        Route::get('api-keys', [ClientApiKeyController::class, 'index']);
        Route::post('api-keys', [ClientApiKeyController::class, 'store']);
        Route::post('api-keys/regenerate', [ClientApiKeyController::class, 'regenerate']);

        Route::get('payments', [ClientPaymentController::class, 'index']);
        Route::get('payments/quote', [ClientPaymentController::class, 'quote']);
        Route::post('payments/checkout', [ClientPaymentController::class, 'checkout'])->middleware('throttle:checkout');
        Route::get('payments/{payment}', [ClientPaymentController::class, 'show'])->whereNumber('payment');
        Route::post('payments/{payment}/verify', [ClientPaymentController::class, 'verify'])->whereNumber('payment')->middleware('throttle:20,1');

        Route::get('reports/{report}', [ClientReportController::class, 'show']);
        Route::get('reports/{report}/export', [ClientReportController::class, 'export'])->middleware('throttle:20,1');
        Route::get('exports/{dataset}', [ClientReportController::class, 'dataset'])->middleware('throttle:20,1');

        Route::get('orders', [ClientOrderController::class, 'index']);
        Route::post('orders', [ClientOrderController::class, 'store'])->middleware('throttle:checkout');
        Route::get('orders/{order}', [ClientOrderController::class, 'show']);

        Route::get('rewards', [ClientRewardController::class, 'index']);
        Route::get('rewards/catalog', [ClientRewardController::class, 'catalog']);
        Route::get('reward-disbursements', [ClientRewardDisbursementController::class, 'index']);
        Route::get('reward-disbursements/{disbursement}', [ClientRewardDisbursementController::class, 'show'])->whereNumber('disbursement');

        Route::get('campaigns', [ClientCampaignController::class, 'index']);
        Route::post('campaigns', [ClientCampaignController::class, 'store']);
        Route::whereNumber('campaign')->group(function () {
            Route::get('campaigns/{campaign}', [ClientCampaignController::class, 'show']);
            Route::put('campaigns/{campaign}', [ClientCampaignController::class, 'update']);
            Route::delete('campaigns/{campaign}', [ClientCampaignController::class, 'destroy']);
            Route::post('campaigns/{campaign}/fund', [ClientCampaignController::class, 'fund'])->middleware('throttle:checkout');
            Route::post('campaigns/{campaign}/activate', [ClientCampaignController::class, 'activate']);
            Route::post('campaigns/{campaign}/pause', [ClientCampaignController::class, 'pause']);
            Route::post('campaigns/{campaign}/resume', [ClientCampaignController::class, 'resume']);
            Route::post('campaigns/{campaign}/complete', [ClientCampaignController::class, 'complete']);
            Route::post('campaigns/{campaign}/cancel', [ClientCampaignController::class, 'cancel']);
            Route::get('campaigns/{campaign}/analytics', [ClientCampaignController::class, 'analytics']);
            Route::get('campaigns/{campaign}/events', [ClientCampaignController::class, 'events']);
            Route::post('campaigns/{campaign}/events', [ClientCampaignController::class, 'recordEvent']);
        });

        Route::get('surveys', [ClientSurveyController::class, 'index']);
        Route::post('surveys', [ClientSurveyController::class, 'store']);
        Route::whereNumber('survey')->group(function () {
            Route::get('surveys/{survey}', [ClientSurveyController::class, 'show']);
            Route::put('surveys/{survey}', [ClientSurveyController::class, 'update']);
            Route::delete('surveys/{survey}', [ClientSurveyController::class, 'destroy']);
            Route::get('surveys/{survey}/questions', [ClientSurveyController::class, 'questions']);
            Route::put('surveys/{survey}/questions', [ClientSurveyController::class, 'syncQuestions']);
            Route::post('surveys/{survey}/fund', [ClientSurveyController::class, 'fund'])->middleware('throttle:checkout');
            Route::post('surveys/{survey}/activate', [ClientSurveyController::class, 'activate']);
            Route::post('surveys/{survey}/pause', [ClientSurveyController::class, 'pause']);
            Route::post('surveys/{survey}/resume', [ClientSurveyController::class, 'resume']);
            Route::post('surveys/{survey}/complete', [ClientSurveyController::class, 'complete']);
            Route::post('surveys/{survey}/cancel', [ClientSurveyController::class, 'cancel']);
            Route::get('surveys/{survey}/analytics', [ClientSurveyController::class, 'analytics']);
            Route::get('surveys/{survey}/responses', [ClientSurveyController::class, 'responses']);
        });

        Route::get('utilities/catalog', [ClientUtilityController::class, 'catalog']);
        Route::post('utilities/quote', [ClientUtilityController::class, 'quote']);
        Route::post('utilities/lookup', [ClientUtilityController::class, 'lookup'])->middleware('throttle:30,1');
        Route::post('utilities/purchase', [ClientUtilityController::class, 'purchase'])->middleware('throttle:checkout');
        Route::get('utilities/orders', [ClientUtilityController::class, 'orders']);
        Route::get('utilities/batches/{batch}', [ClientUtilityController::class, 'batch'])->where('batch', '[A-Za-z0-9_-]+');
        Route::get('utilities/orders/{order}', [ClientUtilityController::class, 'show'])->whereNumber('order');

        Route::get('sms/balance', [ClientSmsController::class, 'balance']);
        Route::get('sms/units/history', [ClientSmsController::class, 'unitHistory']);
        Route::post('sms/quick', [ClientSmsController::class, 'quick'])->middleware('throttle:sms-api');
        Route::post('sms/bulk', [ClientSmsController::class, 'bulk'])->middleware('throttle:sms-api');
        Route::get('sms', [ClientSmsController::class, 'index']);
        Route::get('sms/{inbox}', [ClientSmsController::class, 'show'])->whereNumber('inbox');
        Route::get('sms/{inbox}/recipients', [ClientSmsController::class, 'recipients'])->whereNumber('inbox');
        Route::post('sms/{inbox}/reblast', [ClientSmsController::class, 'reblast'])->whereNumber('inbox')->middleware('throttle:sms-api');
        Route::post('sms/{inbox}/resend', [ClientSmsController::class, 'resend'])->whereNumber('inbox')->middleware('throttle:sms-api');
    });
});

/*
| Public API for clients' own systems. Authenticate with the client's API key
| (x-api-key header or Bearer). Same responses as the dashboard; request_type = api.
*/
Route::prefix('v1')->middleware(['auth.apikey', 'throttle:sms-api'])->group(function () {
    Route::get('balance', [ClientSmsController::class, 'balance']);
    Route::get('units/history', [ClientSmsController::class, 'unitHistory']);

    Route::post('sms/quick', [ClientSmsController::class, 'quick']);
    Route::post('sms/bulk', [ClientSmsController::class, 'bulk']);
    Route::get('sms', [ClientSmsController::class, 'index']);
    Route::get('sms/{inbox}', [ClientSmsController::class, 'show'])->whereNumber('inbox');
    Route::get('sms/{inbox}/recipients', [ClientSmsController::class, 'recipients'])->whereNumber('inbox');
    Route::post('sms/{inbox}/reblast', [ClientSmsController::class, 'reblast'])->whereNumber('inbox');
    Route::post('sms/{inbox}/resend', [ClientSmsController::class, 'resend'])->whereNumber('inbox');

    Route::get('sender-ids', [ClientSenderIdController::class, 'index']);
    Route::post('sender-ids', [ClientSenderIdController::class, 'store']);
    Route::get('sender-ids/{senderId}', [ClientSenderIdController::class, 'show']);

    Route::get('contact-groups', [ClientContactGroupController::class, 'index']);
});

Route::prefix('admin')->group(function () {
    Route::middleware('throttle:5,1')->group(function () {
        Route::post('request_otp', [AdminAuthController::class, 'requestOtp']);
        Route::post('resend_otp_request', [AdminAuthController::class, 'resendOtpRequest']);
        Route::post('verify_otp', [AdminAuthController::class, 'verifyOtp']);
    });

    Route::middleware(['auth:admin', 'account.active'])->group(function () {
        // Any logged-in admin
        Route::get('profile', [AdminProfileController::class, 'profile']);
        Route::post('profile', [AdminProfileController::class, 'updateProfile'])->middleware('permission:profile.manage');
        Route::put('profile', [AdminProfileController::class, 'updateProfile'])->middleware('permission:profile.manage');
        Route::post('logout', [AdminAuthController::class, 'logout']);

        Route::post('onboard/client', [OnboardingController::class, 'client'])->middleware('permission:onboarding.client');
        Route::post('onboard/admin', [OnboardingController::class, 'admin'])->middleware('permission:onboarding.admin');

        Route::middleware('permission:client.manage')->group(function () {
            Route::get('clients', [AdminClientController::class, 'index']);
            Route::get('clients/{client}', [AdminClientController::class, 'show'])->whereNumber('client');
            Route::put('clients/{client}', [AdminClientController::class, 'update'])->whereNumber('client');
            Route::post('clients/{client}', [AdminClientController::class, 'update'])->whereNumber('client'); // multipart profile image
            Route::put('clients/{client}/status', [AdminClientController::class, 'updateStatus'])->whereNumber('client');
        });

        Route::middleware('permission:admin.manage')->group(function () {
            Route::get('admins', [AdminController::class, 'index']);
            Route::get('admins/{admin}', [AdminController::class, 'show']);
            Route::put('admins/{admin}', [AdminController::class, 'update']);
            Route::post('admins/{admin}', [AdminController::class, 'update']); // multipart profile image
            Route::put('admins/{admin}/status', [AdminController::class, 'updateStatus']);
            Route::put('admins/{admin}/role', [AdminController::class, 'assignRole']);
            Route::delete('admins/{admin}', [AdminController::class, 'destroy']);
        });

        Route::middleware('permission:role.manage')->group(function () {
            Route::get('roles', [RoleController::class, 'index']);
            Route::post('roles', [RoleController::class, 'store']);
            Route::get('roles/{role}', [RoleController::class, 'show']);
            Route::put('roles/{role}', [RoleController::class, 'update']);
            Route::post('roles/assign', [RoleController::class, 'assign']);
        });

        Route::get('permissions', [PermissionController::class, 'index'])->middleware('permission:permission.manage');

        Route::middleware('permission:activity_log.view')->group(function () {
            Route::get('activity-logs/filters', [ActivityLogController::class, 'filters']);
            Route::get('activity-logs', [ActivityLogController::class, 'index']);
            Route::get('clients/{client}/activity-logs', [ActivityLogController::class, 'forClient']);
        });

        Route::middleware('permission:plan.manage')->group(function () {
            Route::get('plans', [AdminPlanController::class, 'index']);
            Route::post('plans', [AdminPlanController::class, 'store']);
            Route::get('plans/{plan}', [AdminPlanController::class, 'show']);
            Route::put('plans/{plan}', [AdminPlanController::class, 'update']);
        });

        // Rewards catalog: priced like plans, so the same permission manages it.
        Route::middleware('permission:plan.manage')->group(function () {
            Route::get('rewards', [AdminRewardController::class, 'index']);
            Route::post('rewards', [AdminRewardController::class, 'store']);
            Route::get('rewards/{reward}', [AdminRewardController::class, 'show'])->whereNumber('reward');
            Route::put('rewards/{reward}', [AdminRewardController::class, 'update'])->whereNumber('reward');
            Route::put('rewards/{reward}/status', [AdminRewardController::class, 'updateStatus'])->whereNumber('reward');
            Route::delete('rewards/{reward}', [AdminRewardController::class, 'destroy'])->whereNumber('reward');
        });

        // Utilities (Swift): provider balance and orders are money data, so they follow payments.
        Route::middleware('permission:payment.view,payment.manage')->group(function () {
            Route::get('utilities/catalog', [AdminUtilityController::class, 'catalog']);
            Route::post('utilities/quote', [AdminUtilityController::class, 'quote']);
            Route::get('utilities/balance', [AdminUtilityController::class, 'balance']);
            Route::get('utilities/profit', [AdminUtilityController::class, 'profit']);
            Route::get('utilities/orders', [AdminUtilityController::class, 'orders']);
            Route::get('utilities/batches/{batch}', [AdminUtilityController::class, 'batch'])->where('batch', '[A-Za-z0-9_-]+');
            Route::get('utilities/orders/{order}', [AdminUtilityController::class, 'show'])->whereNumber('order');

            // Reward payouts (airtime, data and money rewards from campaigns and surveys).
            Route::get('reward-disbursements', [AdminRewardDisbursementController::class, 'index']);
            Route::get('reward-disbursements/{disbursement}', [AdminRewardDisbursementController::class, 'show'])->whereNumber('disbursement');
        });

        // Profit margins on top of the provider's prices.
        Route::middleware('permission:payment.manage')->group(function () {
            Route::get('margins', [AdminMarginController::class, 'index']);
            Route::get('margins/options', [AdminMarginController::class, 'options']);
            Route::post('margins', [AdminMarginController::class, 'store']);
            Route::get('margins/{margin}', [AdminMarginController::class, 'show'])->whereNumber('margin');
            Route::put('margins/{margin}', [AdminMarginController::class, 'update'])->whereNumber('margin');
            Route::delete('margins/{margin}', [AdminMarginController::class, 'destroy'])->whereNumber('margin');
        });

        Route::middleware('permission:subscription.view')->group(function () {
            Route::get('subscriptions', [AdminSubscriptionController::class, 'index']);
            Route::get('subscription-archives', [AdminSubscriptionController::class, 'archives']);
            Route::get('clients/{client}/subscription', [AdminSubscriptionController::class, 'forClient']);
        });
        Route::post('clients/{client}/subscription', [AdminSubscriptionController::class, 'assign'])->middleware('permission:subscription.assign');

        Route::middleware('permission:wallet.view')->group(function () {
            Route::get('wallets', [AdminWalletController::class, 'index']);
            Route::get('wallet-top-ups', [AdminWalletController::class, 'topUps']);
            Route::get('wallet-transactions', [AdminWalletController::class, 'transactions']);
            Route::get('clients/{client}/wallet', [AdminWalletController::class, 'forClient']);
        });

        Route::middleware('permission:payment_gateway.manage')->group(function () {
            Route::get('payment-gateways', [AdminPaymentGatewayController::class, 'index']);
            Route::put('payment-gateways/{code}', [AdminPaymentGatewayController::class, 'update']);
            Route::post('payment-gateways/{code}/primary', [AdminPaymentGatewayController::class, 'primary']);
        });

        Route::middleware('permission:payment_settings.manage')->group(function () {
            Route::get('settings/payments', [AdminPaymentGatewayController::class, 'settings']);
            Route::put('settings/payments', [AdminPaymentGatewayController::class, 'updateSettings']);
        });

        Route::get('reports/{report}', [AdminReportController::class, 'show'])->middleware('permission:report.view');
        Route::get('reports/{report}/export', [AdminReportController::class, 'export'])->middleware('permission:report.export');
        Route::get('exports/{dataset}', [AdminReportController::class, 'dataset'])->middleware('permission:report.export');

        Route::middleware('permission:sender_id.view,sender_id.approve')->group(function () {
            Route::get('sender-ids', [AdminSenderIdController::class, 'index']);
            Route::get('sender-ids/{senderId}', [AdminSenderIdController::class, 'show']);
        });
        Route::post('sender-ids/{senderId}/approve', [AdminSenderIdController::class, 'approve'])->middleware('permission:sender_id.approve');
        Route::post('sender-ids/{senderId}/reject', [AdminSenderIdController::class, 'reject'])->middleware('permission:sender_id.approve');

        Route::middleware('permission:payment.view,payment.manage')->group(function () {
            Route::get('payments', [AdminPaymentController::class, 'index']);
            Route::get('payments/{payment}', [AdminPaymentController::class, 'show']);
        });
        Route::middleware('permission:payment.manage')->group(function () {
            Route::post('payments/{payment}/verify', [AdminPaymentController::class, 'verify']);
            Route::post('payments/{payment}/complete', [AdminPaymentController::class, 'complete']);
            Route::post('payments/{payment}/fail', [AdminPaymentController::class, 'fail']);
        });

        Route::middleware('permission:order.view')->group(function () {
            Route::get('orders', [AdminOrderController::class, 'index']);
            Route::get('orders/{order}', [AdminOrderController::class, 'show']);
        });

        Route::middleware('permission:sender_id.blacklist')->group(function () {
            Route::get('sender-id-blacklist', [AdminSenderIdBlacklistController::class, 'index']);
            Route::post('sender-id-blacklist', [AdminSenderIdBlacklistController::class, 'store']);
            Route::delete('sender-id-blacklist/{blacklist}', [AdminSenderIdBlacklistController::class, 'destroy']);
        });

        Route::middleware('permission:sms.view')->group(function () {
            Route::get('sms/overview', [AdminSmsController::class, 'overview']);
            Route::get('sms/system', [AdminSmsController::class, 'systemMessages']);
            Route::get('sms', [AdminSmsController::class, 'index']);
            Route::get('sms/{inbox}', [AdminSmsController::class, 'show'])->whereNumber('inbox');
            Route::get('sms/{inbox}/recipients', [AdminSmsController::class, 'recipients'])->whereNumber('inbox');
        });
        Route::post('sms/quick', [AdminSmsController::class, 'quick'])->middleware(['permission:sms.send', 'throttle:30,1']);
        Route::middleware('permission:sms.aggregator')->group(function () {
            Route::get('sms/aggregator/balance', [AdminSmsController::class, 'aggregatorBalance']);
            Route::get('sms/aggregator/requests', [AdminSmsController::class, 'aggregatorRequests']);
            Route::get('sms/aggregator/delivery-receipts/{sequenceNo}', [AdminSmsController::class, 'deliveryReceipt']);
        });
    });
});
