<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\ContactGroup\StoreContactGroupRequest;
use App\Models\ContactGroup;
use App\Services\ContactGroupService;
use App\Support\ApiCode;
use App\Support\ApiReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactGroupController extends Controller
{
    public function __construct(protected ContactGroupService $groups)
    {
    }

    public function stats(Request $request): JsonResponse
    {
        return $this->respondFrom($this->groups->stats($request->user()));
    }

    public function index(Request $request): JsonResponse
    {
        $query = ContactGroup::query()->where('client_id', $request->user()->id)->latest('id');

        $paginator = $this->paginateQuery($request, $query);

        return $this->paginatedApiResponse('Contact groups loaded.', ApiReason::SUCCESS, $paginator, fn (ContactGroup $row) => $this->groups->present($row));
    }

    public function trashed(Request $request): JsonResponse
    {
        $query = ContactGroup::onlyTrashed()->where('client_id', $request->user()->id)->latest('deleted_at');

        $paginator = $this->paginateQuery($request, $query);

        return $this->paginatedApiResponse('Archived contact groups loaded.', ApiReason::SUCCESS, $paginator, fn (ContactGroup $row) => $this->groups->present($row));
    }

    public function store(StoreContactGroupRequest $request): JsonResponse
    {
        return $this->respondFrom($this->groups->create($request->user(), $request->validated()));
    }

    public function show(Request $request, int $contactGroup): JsonResponse
    {
        $group = $this->owned($request, $contactGroup, true);

        if (! $group) {
            return $this->apiResponse(true, 'Contact group not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        return $this->apiResponse(false, 'Contact group loaded.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->groups->present($group));
    }

    public function update(StoreContactGroupRequest $request, int $contactGroup): JsonResponse
    {
        $group = $this->owned($request, $contactGroup);

        if (! $group) {
            return $this->apiResponse(true, 'Contact group not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        return $this->respondFrom($this->groups->update($request->user(), $group, $request->validated()));
    }

    public function destroy(Request $request, int $contactGroup): JsonResponse
    {
        $group = $this->owned($request, $contactGroup);

        if (! $group) {
            return $this->apiResponse(true, 'Contact group not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        return $this->respondFrom($this->groups->softDelete($request->user(), $group));
    }

    public function restore(Request $request, int $contactGroup): JsonResponse
    {
        $group = $this->owned($request, $contactGroup, true);

        if (! $group || ! $group->trashed()) {
            return $this->apiResponse(true, 'Archived contact group not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        return $this->respondFrom($this->groups->restore($request->user(), $group));
    }

    public function forceDestroy(Request $request, int $contactGroup): JsonResponse
    {
        $group = $this->owned($request, $contactGroup, true);

        if (! $group) {
            return $this->apiResponse(true, 'Contact group not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        return $this->respondFrom($this->groups->forceDelete($request->user(), $group));
    }

    protected function owned(Request $request, int $id, bool $withTrashed = false): ?ContactGroup
    {
        $query = ContactGroup::query()->where('client_id', $request->user()->id)->where('id', $id);

        if ($withTrashed) {
            $query->withTrashed();
        }

        return $query->first();
    }
}
