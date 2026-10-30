<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\Contact\StoreContactRequest;
use App\Http\Requests\Contact\UpdateDndRequest;
use App\Http\Requests\Contact\UploadContactsRequest;
use App\Models\Contact;
use App\Models\ContactGroup;
use App\Services\ContactService;
use App\Support\ApiCode;
use App\Support\ApiReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    public function __construct(protected ContactService $contacts)
    {
    }

    public function index(Request $request, int $contactGroup): JsonResponse
    {
        $group = $this->ownedGroup($request, $contactGroup, true);

        if (! $group) {
            return $this->apiResponse(true, 'Contact group not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        $query = Contact::query()->where('contact_group_id', $group->id)->latest('id');

        if ($request->filled('dnd_status')) {
            $query->where('dnd_status', $request->string('dnd_status'));
        }

        $paginator = $this->paginateQuery($request, $query);

        return $this->paginatedApiResponse('Contacts loaded.', ApiReason::SUCCESS, $paginator, fn (Contact $row) => $this->contacts->present($row));
    }

    public function store(StoreContactRequest $request, int $contactGroup): JsonResponse
    {
        $group = $this->ownedGroup($request, $contactGroup);

        if (! $group) {
            return $this->apiResponse(true, 'Contact group not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        return $this->respondFrom($this->contacts->create($request->user(), $group, $request->validated()));
    }

    public function upload(UploadContactsRequest $request, int $contactGroup): JsonResponse
    {
        $group = $this->ownedGroup($request, $contactGroup);

        if (! $group) {
            return $this->apiResponse(true, 'Contact group not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        return $this->respondFrom($this->contacts->queueUpload($request->user(), $group, $request->file('file')));
    }

    public function show(Request $request, int $contactGroup, int $contact): JsonResponse
    {
        $row = $this->ownedContact($request, $contactGroup, $contact);

        if (! $row) {
            return $this->apiResponse(true, 'Contact not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        return $this->apiResponse(false, 'Contact loaded.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->contacts->present($row));
    }

    public function update(StoreContactRequest $request, int $contactGroup, int $contact): JsonResponse
    {
        $group = $this->ownedGroup($request, $contactGroup);
        $row = $this->ownedContact($request, $contactGroup, $contact);

        if (! $group || ! $row) {
            return $this->apiResponse(true, 'Contact not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        return $this->respondFrom($this->contacts->update($group, $row, $request->validated()));
    }

    public function dnd(UpdateDndRequest $request, int $contactGroup, int $contact): JsonResponse
    {
        $group = $this->ownedGroup($request, $contactGroup);
        $row = $this->ownedContact($request, $contactGroup, $contact);

        if (! $group || ! $row) {
            return $this->apiResponse(true, 'Contact not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        return $this->respondFrom($this->contacts->setDnd($group, $row, $request->string('dnd_status')->toString()));
    }

    public function destroy(Request $request, int $contactGroup, int $contact): JsonResponse
    {
        $group = $this->ownedGroup($request, $contactGroup);
        $row = $this->ownedContact($request, $contactGroup, $contact);

        if (! $group || ! $row) {
            return $this->apiResponse(true, 'Contact not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        return $this->respondFrom($this->contacts->delete($group, $row));
    }

    protected function ownedGroup(Request $request, int $id, bool $withTrashed = false): ?ContactGroup
    {
        $query = ContactGroup::query()->where('client_id', $request->user()->id)->where('id', $id);

        if ($withTrashed) {
            $query->withTrashed();
        }

        return $query->first();
    }

    protected function ownedContact(Request $request, int $groupId, int $contactId): ?Contact
    {
        return Contact::query()
            ->where('client_id', $request->user()->id)
            ->where('contact_group_id', $groupId)
            ->where('id', $contactId)
            ->first();
    }
}
