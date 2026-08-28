<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class BusinessProfile extends Model
{
    protected $fillable = [
        'branch_id',
        'name',
        'phone',
        'address',
        'logo_path',
        'primary_color',
        'secondary_color',
        'tax_id',
        'social_links',
        'show_business_details',
        'receipt_footer',
    ];

    protected $hidden = ['logo_path'];

    protected $appends = ['logo_data_url'];

    protected function casts(): array
    {
        return [
            'social_links' => 'array',
            'show_business_details' => 'boolean',
        ];
    }

    public function getLogoDataUrlAttribute(): ?string
    {
        if (! $this->isManagedLogoPath($this->logo_path)) {
            return null;
        }

        $disk = Storage::disk('local');
        try {
            if (! $disk->exists($this->logo_path) || $disk->size($this->logo_path) > 1_048_576) {
                return null;
            }
            $contents = $disk->get($this->logo_path);
        } catch (\Throwable) {
            return null;
        }

        if (! is_string($contents) || strlen($contents) > 1_048_576) {
            return null;
        }
        $info = @getimagesizefromstring($contents);
        $mime = $info['mime'] ?? null;
        $width = (int) ($info[0] ?? 0);
        $height = (int) ($info[1] ?? 0);
        if (! in_array($mime, ['image/png', 'image/jpeg'], true)
            || $width < 1 || $height < 1
            || $width > 2500 || $height > 2500
            || $width * $height > 4_000_000) {
            return null;
        }

        return 'data:'.$mime.';base64,'.base64_encode($contents);
    }

    public function isManagedLogoPath(?string $path): bool
    {
        return is_string($path)
            && preg_match('#^business-logos/branch-[1-9][0-9]*/[0-9a-f-]+\.(?:png|jpg)$#D', $path) === 1;
    }
}
