<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\Contact;
use App\Services\CampaignService;
use App\Support\CampaignLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Public redirect handler for wrapped campaign CTA links (`/c/{campaign}/{code?}`).
 * Records the click event, then sends the recipient straight to the client's destination URL.
 */
class CampaignLinkController extends Controller
{
    public function __construct(protected CampaignService $campaigns) {}

    public function redirect(Request $request, int $campaign, ?string $code = null): RedirectResponse
    {
        $model = Campaign::query()->find($campaign);

        if (! $model || ! in_array($model->status, [Campaign::STATUS_ACTIVE, Campaign::STATUS_PAUSED, Campaign::STATUS_COMPLETED], true)) {
            abort(404);
        }

        $targetUrl = CampaignLink::targetUrl(is_array($model->cta) ? $model->cta : null);

        if (! $targetUrl) {
            abort(404);
        }

        $phone = null;
        $contactId = CampaignLink::decodeContactId($model->id, $code);

        if ($contactId) {
            $contact = Contact::query()
                ->where('client_id', $model->client_id)
                ->whereKey($contactId)
                ->first(['id', 'phone_number']);

            $phone = $contact?->phone_number;
        }

        $this->campaigns->click($model, $phone, [
            'ip' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
            'target_url' => $targetUrl,
        ]);

        return redirect()->away($targetUrl);
    }
}
