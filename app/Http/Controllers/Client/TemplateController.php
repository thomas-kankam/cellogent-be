<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\Template\StoreTemplateRequest;
use App\Models\Template;
use App\Services\TemplateService;
use App\Support\ApiCode;
use App\Support\ApiReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TemplateController extends Controller
{
    public function __construct(protected TemplateService $templates) {}

    public function index(Request $request): JsonResponse
    {
        $client = $request->user();
        $query = $this->templates->indexForClient($client, $request->only(['type', 'occasion_type', 'category', 'owner', 'scope', 'status', 'include_inactive', 'search']));

        return $this->paginatedApiResponse(
            'Templates loaded.',
            ApiReason::SUCCESS,
            $this->paginateQuery($request, $query),
            fn (Template $template) => $this->templates->present($template, $client),
        );
    }

    public function show(Request $request, Template $template): JsonResponse
    {
        $client = $request->user();

        if (! $this->templates->canClientView($client, $template)) {
            return $this->apiResponse(true, 'Template not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        return $this->apiResponse(false, 'Template loaded.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->templates->present($template, $client));
    }

    public function store(StoreTemplateRequest $request): JsonResponse
    {
        return $this->respondFrom($this->templates->createByClient($request->user(), $request->validated()));
    }

    public function update(StoreTemplateRequest $request, Template $template): JsonResponse
    {
        return $this->respondFrom($this->templates->updateByClient($request->user(), $template, $request->validated()));
    }

    public function updateStatus(Request $request, Template $template): JsonResponse
    {
        $raw = strtolower(trim((string) $request->input('status', '')));
        $status = match ($raw) {
            'active', 'activate' => Template::STATUS_ACTIVE,
            'inactive', 'deactivate' => Template::STATUS_INACTIVE,
            default => null,
        };

        if ($status === null) {
            return $this->apiResponse(true, 'Status must be "active" or "inactive".', ApiCode::VALIDATION, ApiReason::VALIDATION_ERROR, [
                'status' => ['Allowed values: active, inactive.'],
            ]);
        }

        return $this->respondFrom($this->templates->setStatusByClient($request->user(), $template, $status));
    }

    public function destroy(Request $request, Template $template): JsonResponse
    {
        return $this->respondFrom($this->templates->deleteByClient($request->user(), $template));
    }
}
