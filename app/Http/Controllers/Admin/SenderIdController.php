<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SenderId\RejectSenderIdRequest;
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
        $query = SenderId::query()->latest('id');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('client_id')) {
            $query->where('client_id', $request->integer('client_id'));
        }

        $paginator = $this->paginateQuery($request, $query);

        return $this->paginatedApiResponse('Sender IDs loaded.', ApiReason::SUCCESS, $paginator, fn (SenderId $row) => $this->senderIds->present($row));
    }

    public function show(SenderId $senderId): JsonResponse
    {
        return $this->apiResponse(false, 'Sender ID loaded.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->senderIds->present($senderId));
    }

    public function approve(Request $request, SenderId $senderId): JsonResponse
    {
        return $this->respondFrom($this->senderIds->approve($request->user(), $senderId));
    }

    public function reject(RejectSenderIdRequest $request, SenderId $senderId): JsonResponse
    {
        return $this->respondFrom($this->senderIds->reject(
            $request->user(),
            $senderId,
            $request->string('rejection_reason')->toString(),
        ));
    }
}
