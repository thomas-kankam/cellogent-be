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
        $query = $this->templates->indexForClient($client, $request->only(['type', 'occasion_type', 'category', 'owner', 'scope', 'search']));

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

    public function destroy(Request $request, Template $template): JsonResponse
    {
        return $this->respondFrom($this->templates->deleteByClient($request->user(), $template));
    }
}
