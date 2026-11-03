<?php

namespace App\Models;

use App\Support\ContactImportReasons;
use App\Support\ContactUploadFinalizer;
use Illuminate\Database\Eloquent\Model;

class ContactImport extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    /** Statuses that still appear in the upload list for the frontend. */
    public const LISTABLE = ['queued', 'processing', 'completed', 'failed'];

    protected $fillable = [
        'id',
        'client_id',
        'contact_group_id',
        'file_name',
        'file_size',
        'mime_type',
        'status',
        'total',
        'inserted',
        'skipped',
        'failed',
        'chunks_expected',
        'chunks_done',
        'skip_reasons',
        'message',
    ];

    protected function casts(): array
    {
        return [
            'skip_reasons' => 'array',
        ];
    }

    public function scopeListable($query)
    {
        return $query->whereIn('status', self::LISTABLE);
    }

    /**
     * Saved row, with live cache counters while the import is still running.
     *
     * @return array<string, mixed>
     */
    public function present(): array
    {
        $live = in_array($this->status, ['queued', 'processing'], true)
            ? ContactUploadFinalizer::liveCounts($this->id)
            : [];

        $total = (int) ($live['total'] ?? $this->total);
        $inserted = (int) ($live['inserted'] ?? $this->inserted);
        $skipped = (int) ($live['skipped'] ?? $this->skipped);
        $failed = (int) ($live['failed'] ?? $this->failed);
        $expected = (int) ($live['chunks_expected'] ?? $this->chunks_expected);
        $done = (int) ($live['chunks_done'] ?? $this->chunks_done);
        $reasonCounts = in_array($this->status, ['queued', 'processing'], true)
            ? ContactUploadFinalizer::liveReasons($this->id)
            : (is_array($this->skip_reasons) ? $this->skip_reasons : []);

        return [
            'import_id' => $this->id,
            'contact_group_id' => (int) $this->contact_group_id,
            'file_name' => $this->file_name,
            'file_size' => (int) $this->file_size,
            'mime_type' => $this->mime_type,
            'status' => $this->status,
            'total' => $total,
            'inserted' => $inserted,
            'skipped' => $skipped,
            'failed' => $failed,
            'chunks_expected' => $expected,
            'chunks_done' => $done,
            'progress' => $this->status === 'completed'
                ? 100
                : ($expected > 0 ? min(99, (int) floor($done / $expected * 100)) : 0),
            'reasons' => ContactImportReasons::present($reasonCounts),
            'message' => $this->message,
            'created_at' => optional($this->created_at)->toDateTimeString(),
            'updated_at' => optional($this->updated_at)->toDateTimeString(),
        ];
    }
}
