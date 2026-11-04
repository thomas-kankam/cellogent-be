<?php

namespace App\Services\Sms;

final class KairosResponse
{
    /**
     * @param  array<string, mixed>  $body
     */
    public function __construct(
        public bool $ok,
        public int $httpStatus,
        public array $body,
        public ?string $error,
        public int $durationMs,
    ) {}

    public function statusMessage(): ?string
    {
        return $this->body['statusMessage'] ?? $this->body['message'] ?? $this->error;
    }

    public function transactionId(): ?string
    {
        return $this->body['transactionId'] ?? null;
    }

    public function sequenceNo(): ?string
    {
        return $this->body['sequenceNo'] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return is_array($this->body['data'] ?? null) ? $this->body['data'] : [];
    }

    /**
     * Accepted messages keyed by recipient: [to => [sequence_no, message_id, job_id]].
     *
     * @return array<string, array{sequence_no: ?string, message_id: ?string, job_id: ?string}>
     */
    public function messagesByRecipient(): array
    {
        $out = [];

        foreach ($this->data()['batches'] ?? [] as $batch) {
            foreach ($batch['messages'] ?? [] as $message) {
                $to = (string) ($message['to'] ?? '');

                if ($to === '') {
                    continue;
                }

                $out[$to] = [
                    'sequence_no' => $message['sequenceNo'] ?? null,
                    'message_id' => $message['transactionId'] ?? null,
                    'job_id' => $batch['jobId'] ?? null,
                ];
            }
        }

        return $out;
    }

    public function messageCount(): int
    {
        $count = 0;

        foreach ($this->data()['batches'] ?? [] as $batch) {
            $count += count($batch['messages'] ?? []);
        }

        return $count;
    }
}
