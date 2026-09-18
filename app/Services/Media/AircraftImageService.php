<?php

namespace App\Services\Media;

use App\Models\AircraftType;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class AircraftImageService
{
    private const ENDPOINT = 'https://commons.wikimedia.org/w/api.php';

    public function resolve(AircraftType $type): ?array
    {
        $cacheKey = 'aircraft-photo:v2:'.$type->id;

        $cached = Cache::remember($cacheKey, now()->addDays(30), function () use ($type): array {
            try {
                foreach ($this->queries($type) as $query) {
                    $result = $this->search($query, $type);

                    if ($result) {
                        return ['found' => true, 'image' => $result];
                    }
                }
            } catch (Throwable) {
                // Internet images are optional UI enrichment. The Blade component
                // retains its local vector fallback if Commons is unavailable.
            }

            return ['found' => false];
        });

        return ($cached['found'] ?? false)
            ? ($cached['image'] ?? null)
            : null;
    }

    private function queries(AircraftType $type): array
    {
        $queries = [
            trim($type->manufacturer.' '.$type->model.' aircraft'),
            trim($type->manufacturer.' '.$type->variant.' aircraft'),
            trim($type->manufacturer.' '.$type->icao_type_code.' aircraft'),
        ];

        return array_values(array_unique(array_filter($queries)));
    }

    private function search(string $query, AircraftType $type): ?array
    {
        $response = Http::acceptJson()
            ->withHeaders([
                'User-Agent' => 'AirlineEmpire/1.0 (aircraft image lookup; https://airline.obermeier-it.de)',
            ])
            ->timeout(5)
            ->retry(1, 150)
            ->get(self::ENDPOINT, [
                'action' => 'query',
                'format' => 'json',
                'formatversion' => 2,
                'generator' => 'search',
                'gsrsearch' => $query,
                'gsrnamespace' => 6,
                'gsrlimit' => 10,
                'prop' => 'imageinfo',
                'iiprop' => 'url|size|mime|extmetadata',
                'iiurlwidth' => 1200,
                'iiextmetadatalanguage' => 'de',
                'iiextmetadatafilter' => 'LicenseShortName|LicenseUrl|Artist|Credit|ImageDescription',
            ]);

        if (! $response->successful()) {
            return null;
        }

        $pages = collect($response->json('query.pages', []))
            ->sortBy('index')
            ->values();

        foreach ($pages as $page) {
            $info = data_get($page, 'imageinfo.0', []);
            $title = (string) ($page['title'] ?? '');
            $mime = (string) ($info['mime'] ?? '');

            if (! $this->isUsablePhoto($title, $mime, (int) ($info['width'] ?? 0))) {
                continue;
            }

            $imageUrl = (string) ($info['thumburl'] ?? $info['url'] ?? '');
            $sourceUrl = (string) ($info['descriptionurl'] ?? '');

            if ($imageUrl === '' || $sourceUrl === '') {
                continue;
            }

            $metadata = (array) ($info['extmetadata'] ?? []);

            return [
                'image_url' => $imageUrl,
                'source_url' => $sourceUrl,
                'file_title' => Str::after($title, 'File:'),
                'author' => $this->cleanMetadata(data_get($metadata, 'Artist.value')),
                'credit' => $this->cleanMetadata(data_get($metadata, 'Credit.value')),
                'license' => $this->cleanMetadata(data_get($metadata, 'LicenseShortName.value')) ?: 'Wikimedia Commons',
                'license_url' => (string) data_get($metadata, 'LicenseUrl.value', ''),
                'provider' => 'Wikimedia Commons',
                'aircraft' => trim($type->manufacturer.' '.$type->model),
            ];
        }

        return null;
    }

    private function isUsablePhoto(string $title, string $mime, int $width): bool
    {
        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return false;
        }

        if ($width > 0 && $width < 640) {
            return false;
        }

        $title = Str::lower($title);
        $reject = [
            'logo',
            'cockpit',
            'cabin',
            'interior',
            'seat map',
            'seating',
            'diagram',
            'drawing',
            'blueprint',
            'engine',
            'winglet',
            'wing ',
            'museum model',
            'scale model',
        ];

        foreach ($reject as $term) {
            if (Str::contains($title, $term)) {
                return false;
            }
        }

        return true;
    }

    private function cleanMetadata(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $clean = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $clean = preg_replace('/\s+/', ' ', $clean);

        return Str::limit(trim((string) $clean), 160);
    }
}
