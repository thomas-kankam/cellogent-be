<?php

namespace App\Http\Controllers\Admin;

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
        $query = $this->templates->indexForAdmin($request->only(['type', 'occasion_type', 'category', 'status', 'search']));

        return $this->paginatedApiResponse(
            'Templates loaded.',
            ApiReason::SUCCESS,
            $this->paginateQuery($request, $query),
            fn (Template $template) => $this->templates->present($template),
        );
    }

    public function show(Template $template): JsonResponse
    {
        if (! $template->isGlobal()) {
            return $this->apiResponse(true, 'Template not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        return $this->apiResponse(false, 'Template loaded.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->templates->present($template));
    }

    public function store(StoreTemplateRequest $request): JsonResponse
    {
        return $this->respondFrom($this->templates->createByAdmin($request->user(), $request->validated()));
    }

    public function update(StoreTemplateRequest $request, Template $template): JsonResponse
    {
        return $this->respondFrom($this->templates->updateByAdmin($request->user(), $template, $request->validated()));
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

        return $this->respondFrom($this->templates->setStatusByAdmin($request->user(), $template, $status));
    }

    public function destroy(Request $request, Template $template): JsonResponse
    {
        return $this->respondFrom($this->templates->deleteByAdmin($request->user(), $template));
    }
}
