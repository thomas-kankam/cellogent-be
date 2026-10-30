<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\SenderId\StoreSenderIdRequest;
use App\Models\SenderId;
use App\Services\SenderIdService;
use App\Support\ApiCode;
use App\Support\ApiReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SenderIdController extends Controller
{
    public function __construct(protected SenderIdService $senderIds)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = SenderId::query()->where('client_id', $request->user()->id)->latest('id');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        $paginator = $this->paginateQuery($request, $query);

        return $this->paginatedApiResponse('Sender IDs loaded.', ApiReason::SUCCESS, $paginator, fn (SenderId $row) => $this->senderIds->present($row));
    }

    public function show(Request $request, SenderId $senderId): JsonResponse
    {
        if ((int) $senderId->client_id !== (int) $request->user()->id) {
            return $this->apiResponse(true, 'Sender ID not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        return $this->apiResponse(false, 'Sender ID loaded.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->senderIds->present($senderId));
    }

    public function store(StoreSenderIdRequest $request): JsonResponse
    {
        return $this->respondFrom($this->senderIds->request($request->user(), $request->string('name')->toString()));
    }

    public function destroy(Request $request, SenderId $senderId): JsonResponse
    {
        if ((int) $senderId->client_id !== (int) $request->user()->id) {
            return $this->apiResponse(true, 'Sender ID not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        return $this->respondFrom($this->senderIds->delete($request->user(), $senderId));
    }
}
