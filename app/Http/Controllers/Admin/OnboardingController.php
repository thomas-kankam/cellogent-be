<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\OnboardAdminRequest;
use App\Http\Requests\Admin\OnboardClientRequest;
use App\Services\OnboardingService;
use Illuminate\Http\JsonResponse;

class OnboardingController extends Controller
{
    public function __construct(protected OnboardingService $onboarding)
    {
    }

    public function client(OnboardClientRequest $request): JsonResponse
    {
        return $this->respondFrom($this->onboarding->adminOnboardClient(
            $request->user(),
            $request->validated(),
            $request->file('profile_image'),
        ));
    }

    public function admin(OnboardAdminRequest $request): JsonResponse
    {
        return $this->respondFrom($this->onboarding->adminOnboardAdmin(
            $request->user(),
            $request->validated(),
            $request->file('profile_image'),
        ));
    }
}
