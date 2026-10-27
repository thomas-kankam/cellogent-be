<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\UpdateProfileRequest;
use App\Services\ProfilePresenter;
use App\Services\ProfileService;
use App\Support\ApiCode;
use App\Support\ApiReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientProfileController extends Controller
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
            $request->safe()->except(['profile_image']),
            $request->file('profile_image'),
        );

        return $this->apiResponse(false, 'Profile updated.', ApiCode::SUCCESS, ApiReason::SUCCESS, $account);
    }
}
