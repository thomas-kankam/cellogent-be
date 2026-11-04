<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Services\ExportService;
use App\Services\ReportService;
use App\Support\ApiCode;
use App\Support\ApiReason;
use App\Support\TabularExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReportController extends Controller
{
    protected const FILTERS = ['period', 'from', 'to', 'type', 'request_type', 'purpose', 'status', 'inbox_id', 'format'];

    public function __construct(
        protected ReportService $reports,
        protected ExportService $exports,
    ) {}

    public function show(Request $request, string $report): JsonResponse
    {
        if (! in_array($report, ReportService::CLIENT_REPORTS, true)) {
            return $this->apiResponse(true, 'Report not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        return $this->respondFrom($this->reports->run($report, $request->user()->id, $request->only(self::FILTERS)));
    }

    /**
     * Downloads the aggregated report (one row per period bucket).
     */
    public function export(Request $request, string $report): JsonResponse|BinaryFileResponse
    {
        if (! in_array($report, ReportService::CLIENT_REPORTS, true)) {
            return $this->apiResponse(true, 'Report not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        $result = $this->reports->run($report, $request->user()->id, $request->only(self::FILTERS));

        if ($result['in_error']) {
            return $this->respondFrom($result);
        }

        return self::downloadReport($result['data'], (string) $request->input('format', 'csv'));
    }

    /**
     * Downloads row-level data (every payment, transaction, message, recipient).
     */
    public function dataset(Request $request, string $dataset): JsonResponse|BinaryFileResponse
    {
        if (! in_array($dataset, ExportService::DATASETS, true)) {
            return $this->apiResponse(true, 'Export not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        $response = $this->exports->download($dataset, $request->user()->id, $request->only(self::FILTERS));

        return is_string($response)
            ? $this->apiResponse(true, $response, ApiCode::VALIDATION, ApiReason::VALIDATION_ERROR)
            : $response;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function downloadReport(array $data, string $format): BinaryFileResponse
    {
        $rows = $data['rows'];
        $headers = $rows === []
            ? ['bucket']
            : array_map(fn ($k) => ucfirst(str_replace('_', ' ', $k)), array_keys($rows[0]));

        $name = $data['report'].'-report-'.$data['period'].'-'.$data['from'].'-to-'.$data['to'];

        return TabularExport::download($name, $headers, $rows, $format);
    }
}
