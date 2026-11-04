<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Client\ReportController as ClientReportController;
use App\Http\Controllers\Controller;
use App\Services\ExportService;
use App\Services\ReportService;
use App\Support\ApiCode;
use App\Support\ApiReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Same reports as clients see, across all clients (or one via ?client_id=), plus revenue.
 */
class ReportController extends Controller
{
    protected const FILTERS = ['period', 'from', 'to', 'type', 'request_type', 'purpose', 'status', 'inbox_id', 'format'];

    public function __construct(
        protected ReportService $reports,
        protected ExportService $exports,
    ) {}

    public function show(Request $request, string $report): JsonResponse
    {
        if (! in_array($report, ReportService::ADMIN_REPORTS, true)) {
            return $this->apiResponse(true, 'Report not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        return $this->respondFrom($this->reports->run($report, $this->clientId($request), $request->only(self::FILTERS)));
    }

    public function export(Request $request, string $report): JsonResponse|BinaryFileResponse
    {
        if (! in_array($report, ReportService::ADMIN_REPORTS, true)) {
            return $this->apiResponse(true, 'Report not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        $result = $this->reports->run($report, $this->clientId($request), $request->only(self::FILTERS));

        if ($result['in_error']) {
            return $this->respondFrom($result);
        }

        return ClientReportController::downloadReport($result['data'], (string) $request->input('format', 'csv'));
    }

    public function dataset(Request $request, string $dataset): JsonResponse|BinaryFileResponse
    {
        if (! in_array($dataset, ExportService::DATASETS, true)) {
            return $this->apiResponse(true, 'Export not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        $response = $this->exports->download($dataset, $this->clientId($request), $request->only(self::FILTERS), true);

        return is_string($response)
            ? $this->apiResponse(true, $response, ApiCode::VALIDATION, ApiReason::VALIDATION_ERROR)
            : $response;
    }

    protected function clientId(Request $request): ?int
    {
        return $request->filled('client_id') ? $request->integer('client_id') : null;
    }
}
