<?php

namespace App\Concerns;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Shared profile-photo upload used by Client and Admin models.
 * Accepts a real file (multipart), never a base64 string.
 *
 * @mixin \Illuminate\Database\Eloquent\Model
 * @property string|null $profile_image
 */
trait HasProfileImage
{
    public function storeProfileImage(UploadedFile $file, string $folder): string
    {
        $name = Str::uuid()->toString().'.'.$file->getClientOriginalExtension();
        $path = $file->storeAs('profiles/'.$folder, $name, 'public');

        $this->forceFill(['profile_image' => $path])->save();

        return Storage::disk('public')->url($path);
    }

    public function profileImageUrl(): ?string
    {
        $path = $this->getAttribute('profile_image');

        if (! is_string($path) || $path === '') {
            return null;
        }

        if (str_starts_with($path, 'http')) {
            return $path;
        }

        return Storage::disk('public')->url($path);
    }
}
