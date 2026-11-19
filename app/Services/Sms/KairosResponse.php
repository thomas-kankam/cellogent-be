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
        /**
         * True when Kairos may already have accepted the message (read timeout, 5xx).
         * Callers must NOT refund units or retry the same recipients.
         */
        public bool $uncertain = false,
    ) {}

    public function statusMessage(): ?string
    {
        return $this->body['statusMessage'] ?? $this->body['message'] ?? $this->error;
    }

    public function transactionId(): ?string
    {
        $id = $this->body['transactionId']
            ?? $this->data()['transactionId']
            ?? $this->body['messageId']
            ?? $this->data()['messageId']
            ?? null;

        return $id !== null && (string) $id !== '' ? (string) $id : null;
    }

    public function sequenceNo(): ?string
    {
        $seq = $this->body['sequenceNo']
            ?? $this->data()['sequenceNo']
            ?? null;

        return $seq !== null && (string) $seq !== '' ? (string) $seq : null;
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
        $topTransactionId = $this->transactionId();
        $topSequenceNo = $this->sequenceNo();
        $topJobId = $this->data()['jobId'] ?? $this->body['jobId'] ?? null;

        foreach ($this->data()['batches'] ?? [] as $batch) {
            $batchTransactionId = $batch['transactionId'] ?? $batch['messageId'] ?? $topTransactionId;
            $batchJobId = $batch['jobId'] ?? $topJobId;

            foreach ($batch['messages'] ?? [] as $message) {
                $to = (string) ($message['to'] ?? '');

                if ($to === '') {
                    continue;
                }

                $seq = $message['sequenceNo'] ?? $topSequenceNo;
                $msgId = $message['transactionId']
                    ?? $message['messageId']
                    ?? $message['id']
                    ?? $batchTransactionId
                    ?? $seq;

                $out[$to] = [
                    'sequence_no' => $seq !== null ? (string) $seq : null,
                    'message_id' => $msgId !== null ? (string) $msgId : null,
                    'job_id' => $batchJobId !== null ? (string) $batchJobId : null,
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
