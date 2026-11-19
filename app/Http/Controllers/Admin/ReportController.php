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

        $clientId = $this->clientId($request);
        if ($clientId === false) {
            return $this->apiResponse(true, 'client_id must be a positive integer.', ApiCode::VALIDATION, ApiReason::VALIDATION_ERROR, ['client_id' => ['Must be a positive integer.']]);
        }

        return $this->respondFrom($this->reports->run($report, $clientId, $request->only(self::FILTERS)));
    }

    public function export(Request $request, string $report): JsonResponse|BinaryFileResponse
    {
        if (! in_array($report, ReportService::ADMIN_REPORTS, true)) {
            return $this->apiResponse(true, 'Report not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        $clientId = $this->clientId($request);
        if ($clientId === false) {
            return $this->apiResponse(true, 'client_id must be a positive integer.', ApiCode::VALIDATION, ApiReason::VALIDATION_ERROR, ['client_id' => ['Must be a positive integer.']]);
        }

        $result = $this->reports->run($report, $clientId, $request->only(self::FILTERS));

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

        $clientId = $this->clientId($request);
        if ($clientId === false) {
            return $this->apiResponse(true, 'client_id must be a positive integer.', ApiCode::VALIDATION, ApiReason::VALIDATION_ERROR, ['client_id' => ['Must be a positive integer.']]);
        }

        $response = $this->exports->download($dataset, $clientId, $request->only(self::FILTERS), true);

        return is_string($response)
            ? $this->apiResponse(true, $response, ApiCode::VALIDATION, ApiReason::VALIDATION_ERROR)
            : $response;
    }

    protected function clientId(Request $request): int|null|false
    {
        if (! $request->filled('client_id')) {
            return null;
        }

        $raw = $request->input('client_id');

        if (! is_numeric($raw) || (int) $raw < 1 || (string) (int) $raw !== trim((string) $raw)) {
            return false;
        }

        return (int) $raw;
    }
}
