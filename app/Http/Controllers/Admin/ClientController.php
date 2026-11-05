<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateClientRequest;
use App\Http\Requests\Admin\UpdateClientStatusRequest;
use App\Models\Client;
use App\Services\ClientService;
use App\Support\ApiCode;
use App\Support\ApiReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    public function __construct(protected ClientService $clients) {}

    public function index(Request $request): JsonResponse
    {
        $query = Client::query()->with(['wallet', 'subscription', 'onboardedBy'])->latest('id');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('account_type')) {
            $query->where('account_type', $request->string('account_type'));
        }
        if ($request->filled('onboarding_type')) {
            $query->where('onboarding_type', $request->string('onboarding_type'));
        }
        if ($request->has('is_verified')) {
            $query->where('is_verified', filter_var($request->input('is_verified'), FILTER_VALIDATE_BOOLEAN));
        }
        if ($request->filled('search')) {
            $search = '%'.trim((string) $request->string('search')).'%';
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', $search)
                    ->orWhere('last_name', 'like', $search)
                    ->orWhere('email', 'like', $search)
                    ->orWhere('phone_number', 'like', $search)
                    ->orWhere('business_name', 'like', $search);
            });
        }

        $paginator = $this->paginateQuery($request, $query);

        return $this->paginatedApiResponse(
            'Clients loaded.',
            ApiReason::SUCCESS,
            $paginator,
            fn (Client $client) => $this->clients->present($client),
        );
    }

    public function show(Client $client): JsonResponse
    {
        return $this->apiResponse(false, 'Client loaded.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->clients->present($client));
    }

    public function update(UpdateClientRequest $request, Client $client): JsonResponse
    {
        return $this->respondFrom($this->clients->update(
            $request->user(),
            $client,
            $request->safe()->except('profile_image'),
            $request->file('profile_image'),
        ));
    }

    public function updateStatus(UpdateClientStatusRequest $request, Client $client): JsonResponse
    {
        return $this->respondFrom($this->clients->updateStatus(
            $request->user(),
            $client,
            (string) $request->validated('status'),
        ));
    }
}
