<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SenderId\StoreSenderIdBlacklistRequest;
use App\Models\SenderIdBlacklist;
use App\Services\SenderIdService;
use App\Support\ApiReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SenderIdBlacklistController extends Controller
{
    public function __construct(protected SenderIdService $senderIds) {}

    public function index(Request $request): JsonResponse
    {
        $query = SenderIdBlacklist::query()->latest('id');

        if ($request->filled('search')) {
            $query->where('normalized', 'like', '%'.SenderIdBlacklist::normalize($request->string('search')->toString()).'%');
        }

        foreach (['match_type', 'source'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }

        return $this->paginatedApiResponse('Blacklist loaded.', ApiReason::SUCCESS, $this->paginateQuery($request, $query), fn (SenderIdBlacklist $row) => $this->senderIds->presentBlacklist($row));
    }

    public function store(StoreSenderIdBlacklistRequest $request): JsonResponse
    {
        return $this->respondFrom($this->senderIds->blacklist($request->user(), $request->validated()));
    }

    public function destroy(Request $request, SenderIdBlacklist $blacklist): JsonResponse
    {
        return $this->respondFrom($this->senderIds->unblacklist($request->user(), $blacklist));
    }
}
