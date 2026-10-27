<?php

namespace App\Services;

use App\Concerns\LogsActivity;
use App\Jobs\ProcessProfileImageJob;
use App\Models\Actor;
use Illuminate\Http\UploadedFile;

class ProfileService
{
    use LogsActivity;

    public function __construct(protected ProfilePresenter $profiles)
    {
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function update(Actor $actor, array $attributes, ?UploadedFile $image = null): array
    {
        $actor->fill($attributes)->save();

        if ($image) {
            $actor->storeProfileImage($image, $actor->guardName());
            ProcessProfileImageJob::dispatch($actor->guardName(), (int) $actor->getKey(), (string) $actor->profile_image);
        }

        $this->activity()->forActorSelf($actor, 'Profile updated', 'profile', 'updated');

        return $this->profiles->present($actor->fresh());
    }
}
