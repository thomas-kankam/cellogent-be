<?php

namespace App\Services;

use App\Concerns\ReturnsServiceResult;
use App\Jobs\ProcessBeneficiariesJob;
use App\Models\Client;
use App\Models\Contact;
use App\Models\ContactGroup;
use App\Services\Utilities\SwiftClient;
use App\Support\ApiCode;
use App\Support\ApiReason;
use App\Support\GhanaPhone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Swift data bundles for some networks (e.g. Affordable MTN SME) can only go to numbers the
 * carrier has registered as beneficiaries. This checks the contacts in a group against Swift
 * and submits the unregistered ones, keeping the result on each contact.
 *
 * Verify: after uploads + daily at midnight (unknown / submitted).
 * Submit: weekly for unverified numbers; following midnight verifies then see if Swift
 * approved them (submitted → verified). Manual verify/submit endpoints still work.
 *
 * Both actions are queued: Swift answers one number per request and can be slow.
 */
class ContactBeneficiaryService
{
    use ReturnsServiceResult;

    public const ACTION_VERIFY = 'verify';

    public const ACTION_SUBMIT = 'submit';

    /** Contacts handled per job run (the job re-queues itself for the next page). */
    public const PAGE_SIZE = 25;

    /** Swift accepts at most this many numbers per submit request. */
    public const SUBMIT_CHUNK = 30;

    /** Statuses the automatic / scheduled verify pass still needs to check. */
    public const VERIFY_PENDING = [
        Contact::BENEFICIARY_UNKNOWN,
        Contact::BENEFICIARY_SUBMITTED,
    ];

    public function __construct(protected SwiftClient $swift) {}

    /**
     * Queue a check of the group's pending numbers (or only `$phones`, any status).
     *
     * @param  list<string>|null  $phones
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function queueVerify(Client $client, ContactGroup $group, ?array $phones = null): array
    {
        // Explicit phone list → check those as asked. Otherwise only numbers that still need a check.
        $only = $phones === null ? self::VERIFY_PENDING : null;

        return $this->queue(self::ACTION_VERIFY, $client, $group, $phones, $only);
    }

    /**
     * Queue submitting the group's unverified numbers (or only `$phones`) to Swift.
     *
     * @param  list<string>|null  $phones
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function queueSubmit(Client $client, ContactGroup $group, ?array $phones = null): array
    {
        return $this->queue(self::ACTION_SUBMIT, $client, $group, $phones, [Contact::BENEFICIARY_UNVERIFIED]);
    }

    /**
     * After an upload (or a single add): start verifying that group's pending numbers.
     */
    public function queueVerifyGroup(int $clientId, int $groupId): void
    {
        $pending = (int) $this->contacts($clientId, $groupId, null, self::VERIFY_PENDING)->count();

        if ($pending < 1) {
            return;
        }

        $this->dispatchStarter(self::ACTION_VERIFY, $clientId, $groupId, null);
    }

    /**
     * Schedule helper: kick off verify jobs for groups that still have unknown/submitted numbers.
     *
     * @return array{groups: int, contacts: int}
     */
    public function dispatchPendingVerify(int $limitGroups = 40): array
    {
        return $this->dispatchPendingByStatus(self::ACTION_VERIFY, self::VERIFY_PENDING, $limitGroups);
    }

    /**
     * After verify marks numbers unverified: register them with Swift (status → submitted).
     */
    public function queueSubmitGroup(int $clientId, int $groupId): void
    {
        $pending = (int) $this->contacts($clientId, $groupId, null, [Contact::BENEFICIARY_UNVERIFIED])->count();

        if ($pending < 1) {
            return;
        }

        $this->dispatchStarter(self::ACTION_SUBMIT, $clientId, $groupId, null);
    }

    /**
     * Schedule helper: submit unverified numbers so a later verify can see if they are approved.
     *
     * @return array{groups: int, contacts: int}
     */
    public function dispatchPendingSubmit(int $limitGroups = 40): array
    {
        return $this->dispatchPendingByStatus(self::ACTION_SUBMIT, [Contact::BENEFICIARY_UNVERIFIED], $limitGroups);
    }

    /**
     * @param  list<string>  $statuses
     * @return array{groups: int, contacts: int}
     */
    protected function dispatchPendingByStatus(string $action, array $statuses, int $limitGroups): array
    {
        $rows = Contact::query()
            ->visible()
            ->whereIn('beneficiary_status', $statuses)
            ->selectRaw('client_id, contact_group_id, COUNT(*) as pending')
            ->groupBy('client_id', 'contact_group_id')
            ->orderByDesc('pending')
            ->limit($limitGroups)
            ->get();

        $started = 0;
        $contacts = 0;

        foreach ($rows as $row) {
            if ($this->dispatchStarter($action, (int) $row->client_id, (int) $row->contact_group_id, null)) {
                $started++;
                $contacts += (int) $row->pending;
            }
        }

        return ['groups' => $started, 'contacts' => $contacts];
    }

    /**
     * Start a whole-group chain once. Phone-scoped jobs always start (client asked for those numbers).
     *
     * @param  list<string>|null  $phones
     */
    protected function dispatchStarter(string $action, int $clientId, int $groupId, ?array $phones): bool
    {
        if ($phones === null) {
            $key = "beneficiaries:{$action}:group:{$groupId}";

            // Skip if a verify/submit chain for this group is already running.
            if (! Cache::add($key, 1, 1800)) {
                return false;
            }
        }

        ProcessBeneficiariesJob::dispatch($action, $clientId, $groupId, $phones, 0);

        return true;
    }

    /**
     * @param  list<string>|null  $phones
     * @param  list<string>|null  $onlyStatuses
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    protected function queue(string $action, Client $client, ContactGroup $group, ?array $phones, ?array $onlyStatuses): array
    {
        $normalized = null;

        if ($phones !== null) {
            $normalized = [];
            $invalid = [];

            foreach ($phones as $phone) {
                $mobile = GhanaPhone::toMobile((string) $phone);
                $mobile ? $normalized[$mobile] = $mobile : $invalid[] = (string) $phone;
            }

            if ($invalid !== []) {
                return $this->result(true, 'Some phone numbers are not valid.', ApiCode::VALIDATION, ApiReason::VALIDATION_ERROR, ['phones' => $invalid]);
            }

            $normalized = array_values($normalized);
        }

        $total = (int) $this->contacts($client->id, $group->id, $normalized, $onlyStatuses)->count();

        if ($total === 0) {
            return $this->result(true, $action === self::ACTION_SUBMIT
                ? 'There are no unverified numbers to submit. Verify the group first.'
                : 'There are no contacts to verify.', ApiCode::BAD_REQUEST, ApiReason::INVALID);
        }

        $this->dispatchStarter($action, $client->id, $group->id, $normalized);

        return $this->result(false, $action === self::ACTION_SUBMIT
            ? 'Submitting numbers to the network. Check the contacts for their status shortly.'
            : 'Checking numbers. Check the contacts for their status shortly.', ApiCode::SUCCESS, ApiReason::SUCCESS, [
                'action' => $action,
                'queued' => $total,
                'beneficiary_summary' => $this->summary($client->id, $group->id),
            ]);
    }

    /**
     * Contacts per beneficiary status in a group.
     *
     * @return array<string, int>
     */
    public function summary(int $clientId, int $groupId): array
    {
        $counts = Contact::query()
            ->where('client_id', $clientId)
            ->where('contact_group_id', $groupId)
            ->visible()
            ->selectRaw('beneficiary_status, COUNT(*) as total')
            ->groupBy('beneficiary_status')
            ->pluck('total', 'beneficiary_status');

        $summary = [];

        foreach (Contact::BENEFICIARY_STATUSES as $status) {
            $summary[$status] = (int) ($counts[$status] ?? 0);
        }

        return $summary;
    }

    /*
    |--------------------------------------------------------------------------
    | Worker side (ProcessBeneficiariesJob)
    |--------------------------------------------------------------------------
    */

    /**
     * Handle one page of contacts. Returns the last contact id handled, or null when the group is done.
     *
     * @param  list<string>|null  $phones
     */
    public function runPage(string $action, int $clientId, int $groupId, ?array $phones, int $afterId): ?int
    {
        // Submit: only unverified. Verify without phones: unknown + submitted. Verify with phones: those numbers.
        $only = match (true) {
            $action === self::ACTION_SUBMIT => [Contact::BENEFICIARY_UNVERIFIED],
            $phones === null => self::VERIFY_PENDING,
            default => null,
        };
        $size = $action === self::ACTION_SUBMIT ? self::SUBMIT_CHUNK : self::PAGE_SIZE;

        $page = $this->contacts($clientId, $groupId, $phones, $only)
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit($size)
            ->get();

        if ($page->isEmpty()) {
            return null;
        }

        $action === self::ACTION_SUBMIT ? $this->submitContacts($page->all()) : $this->verifyContacts($page->all());

        return $page->count() === $size ? (int) $page->last()->id : null;
    }

    /**
     * @param  list<Contact>  $contacts
     */
    protected function verifyContacts(array $contacts): void
    {
        foreach ($contacts as $contact) {
            $response = $this->swift->verifyBeneficiary(['phone' => $this->local($contact->phone_number)]);

            if (! $response->ok) {
                // Leave the status as it was; only note why the check did not happen.
                $meta = (array) $contact->beneficiary_meta;
                $meta['error'] = Str::limit((string) ($response->error ?? 'Verification failed.'), 200, '');
                $contact->update(['beneficiary_meta' => $meta]);

                continue;
            }

            $body = $response->body;

            $contact->update([
                'beneficiary_status' => $this->statusFrom($body),
                'beneficiary_checked_at' => now(),
                'beneficiary_meta' => [
                    'status' => $body['status'] ?? null,
                    'verification_status' => $body['verification_status'] ?? null,
                    'can_order' => $body['can_order'] ?? null,
                    'exists' => $body['exists'] ?? null,
                    'message' => $body['message'] ?? null,
                ],
            ]);
        }
    }

    /**
     * @param  list<Contact>  $contacts
     */
    protected function submitContacts(array $contacts): void
    {
        $numbers = array_map(fn (Contact $c) => $this->local($c->phone_number), $contacts);
        $response = $this->swift->submitNumbers(['numbers' => $numbers]);

        if (! $response->ok) {
            foreach ($contacts as $contact) {
                $meta = (array) $contact->beneficiary_meta;
                $meta['error'] = Str::limit((string) ($response->error ?? 'Submit failed.'), 200, '');
                $contact->update(['beneficiary_meta' => $meta]);
            }

            return;
        }

        foreach ($contacts as $contact) {
            $meta = (array) $contact->beneficiary_meta;
            unset($meta['error']);
            $meta['submitted_at'] = now()->toDateTimeString();
            $meta['message'] = $response->body['message'] ?? $meta['message'] ?? null;

            $contact->update([
                'beneficiary_status' => Contact::BENEFICIARY_SUBMITTED,
                'beneficiary_checked_at' => now(),
                'beneficiary_meta' => $meta,
            ]);
        }
    }

    /**
     * Swift: exists=false → invalid; can_order=true (verified or not required) → verified;
     * a pending registration → submitted; otherwise the number still needs to be submitted.
     *
     * @param  array<string, mixed>  $body
     */
    public function statusFrom(array $body): string
    {
        $state = strtolower((string) ($body['verification_status'] ?? $body['status'] ?? ''));

        if (($body['exists'] ?? true) === false || str_contains($state, 'invalid')) {
            return Contact::BENEFICIARY_INVALID;
        }

        if (($body['can_order'] ?? false) === true || in_array($state, ['verified', 'not_required'], true)) {
            return Contact::BENEFICIARY_VERIFIED;
        }

        if (str_contains($state, 'submit') || str_contains($state, 'pending')) {
            return Contact::BENEFICIARY_SUBMITTED;
        }

        return Contact::BENEFICIARY_UNVERIFIED;
    }

    /**
     * @param  list<string>|null  $phones  normalized 233… numbers
     * @param  list<string>|null  $onlyStatuses
     * @return Builder<Contact>
     */
    protected function contacts(int $clientId, int $groupId, ?array $phones, ?array $onlyStatuses): Builder
    {
        return Contact::query()
            ->where('client_id', $clientId)
            ->where('contact_group_id', $groupId)
            ->visible()
            ->when($phones !== null, fn ($q) => $q->whereIn('phone_number', $phones))
            ->when($onlyStatuses !== null, fn ($q) => $q->whereIn('beneficiary_status', $onlyStatuses));
    }

    protected function local(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return str_starts_with($digits, '233') ? '0'.substr($digits, 3) : $digits;
    }
}
