<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\UpdateProfileRequest;
use App\Services\ProfilePresenter;
use App\Services\ProfileService;
use App\Support\ApiCode;
use App\Support\ApiReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminProfileController extends Controller
{
    public function __construct(
        protected ProfilePresenter $presenter,
        protected ProfileService $profiles,
    ) {
    }

    public function profile(Request $request): JsonResponse
    {
        return $this->apiResponse(
            false,
            'Profile loaded.',
            ApiCode::SUCCESS,
            ApiReason::SUCCESS,
            $this->presenter->present($request->user()),
        );
    }

    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        $account = $this->profiles->update(
            $request->user(),
            $request->safe()->only(['first_name', 'last_name', 'phone_number']),
            $request->file('profile_image'),
        );

        return $this->apiResponse(false, 'Profile updated.', ApiCode::SUCCESS, ApiReason::SUCCESS, $account);
    }
}
