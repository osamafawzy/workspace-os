<?php

namespace App\Support;

use Filament\Support\Colors\Color;
use Illuminate\Support\Facades\Storage;

/**
 * The company's name, logo and colour, as set on Settings → Company.
 *
 * Everything has a fallback, so a fresh install with no settings saved still
 * has a name and a colour rather than blanks.
 */
class Branding
{
    public const DEFAULT_COMPANY = 'Concentrix';

    public const DEFAULT_APP = 'IT Operations';

    public const DEFAULT_COLOR = '#4f46e5';

    public function __construct(protected Settings $settings) {}

    public function companyName(): string
    {
        return $this->settings->get('branding.company_name') ?: self::DEFAULT_COMPANY;
    }

    public function appName(): string
    {
        return $this->settings->get('branding.app_name') ?: self::DEFAULT_APP;
    }

    /** "Concentrix IT Operations" — what the sidebar and page titles show. */
    public function name(): string
    {
        return trim($this->companyName().' '.$this->appName());
    }

    public function logoUrl(): ?string
    {
        return $this->fileUrl('branding.logo');
    }

    /** The logo for dark mode, falling back to the normal one. */
    public function darkLogoUrl(): ?string
    {
        return $this->fileUrl('branding.logo_dark') ?? $this->logoUrl();
    }

    public function primaryColor(): string
    {
        $color = (string) $this->settings->get('branding.primary_color');

        return preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : self::DEFAULT_COLOR;
    }

    /** @return array<string, mixed> */
    public function panelColors(): array
    {
        return [
            'primary' => Color::hex($this->primaryColor()),
            'gray' => Color::Slate,
        ];
    }

    protected function fileUrl(string $key): ?string
    {
        $path = $this->settings->get($key);

        return is_string($path) && $path !== '' ? Storage::disk('public')->url($path) : null;
    }
}
