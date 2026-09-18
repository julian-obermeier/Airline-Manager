@props([
    'group' => 'narrowbody',
    'label' => null,
    'typeId' => null,
    'manufacturer' => null,
    'model' => null,
])

@php
    $bodyLength = match($group) {
        'turboprop' => 228,
        'regional' => 238,
        'widebody' => 282,
        'jumbo' => 298,
        default => 260,
    };
    $bodyStart = 20;
    $bodyEnd = $bodyStart + $bodyLength;
    $wingX = match($group) {
        'turboprop' => 132,
        'regional' => 145,
        'widebody' => 170,
        'jumbo' => 178,
        default => 158,
    };
    $windowCount = match($group) {
        'turboprop' => 11,
        'regional' => 15,
        'widebody' => 20,
        'jumbo' => 23,
        default => 18,
    };

    $aircraftName = trim(($manufacturer ?? '').' '.($model ?? ''));
    $aircraftName = $aircraftName !== '' ? $aircraftName : ($label ? 'Flugzeug '.$label : 'Flugzeug');
    $imageEndpoint = $typeId
        ? route('aircraft-images.show', ['aircraftType' => $typeId])
        : null;
    $svgKey = substr(md5(($typeId ?? 'generic').'|'.($label ?? '').'|'.$group), 0, 8);
@endphp

<div
    {{ $attributes->merge(['class' => 'aircraft-visual aircraft-visual-'.$group]) }}
    @if($imageEndpoint)
        data-aircraft-photo
        data-image-endpoint="{{ $imageEndpoint }}"
    @endif
>
    @if($imageEndpoint)
        <img
            class="aircraft-photo-image"
            data-aircraft-photo-img
            alt="{{ $aircraftName }}"
            loading="lazy"
            decoding="async"
            referrerpolicy="no-referrer"
        >
    @endif

    <div class="aircraft-photo-fallback" data-aircraft-photo-fallback>
        <svg class="aircraft-side-profile" viewBox="0 0 340 125" role="img" aria-label="{{ $aircraftName }}">
            <defs>
                <linearGradient id="bodyGradient-{{ $svgKey }}" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stop-color="#ffffff"/>
                    <stop offset="72%" stop-color="#e7eef7"/>
                    <stop offset="100%" stop-color="#cbd8e7"/>
                </linearGradient>
                <linearGradient id="wingGradient-{{ $svgKey }}" x1="0" y1="0" x2="1" y2="1">
                    <stop offset="0%" stop-color="#dbe7f4"/>
                    <stop offset="100%" stop-color="#9fb4ca"/>
                </linearGradient>
                <filter id="shadow-{{ $svgKey }}" x="-20%" y="-40%" width="140%" height="180%">
                    <feDropShadow dx="0" dy="5" stdDeviation="4" flood-color="#27415f" flood-opacity=".18"/>
                </filter>
            </defs>

            <g filter="url(#shadow-{{ $svgKey }})">
                <ellipse cx="171" cy="105" rx="{{ $group === 'jumbo' ? 145 : 125 }}" ry="7" fill="rgba(44,72,102,.10)"/>

                @if($group === 'jumbo')
                    <path d="M56 61 C82 44 103 41 140 43 L270 45 C296 46 313 54 322 63 C313 72 295 77 270 78 L98 79 C76 78 59 72 48 66 Z"
                          fill="url(#bodyGradient-{{ $svgKey }})" stroke="#8fa6bd" stroke-width="1.2"/>
                    <path d="M85 49 C105 38 130 35 170 36 L257 38 C275 39 292 43 303 49 L282 52 L106 52 Z"
                          fill="#eef4fb" stroke="#b9c9da" stroke-width="1"/>
                @else
                    <path d="M{{ $bodyStart }} 63 C35 53 48 48 68 47 L{{ $bodyEnd - 25 }} 47 C{{ $bodyEnd - 8 }} 48 {{ $bodyEnd }} 55 {{ $bodyEnd + 10 }} 63 C{{ $bodyEnd }} 71 {{ $bodyEnd - 8 }} 77 {{ $bodyEnd - 25 }} 78 L68 78 C48 77 35 73 {{ $bodyStart }} 63 Z"
                          fill="url(#bodyGradient-{{ $svgKey }})" stroke="#8fa6bd" stroke-width="1.2"/>
                @endif

                <path d="M{{ $bodyEnd - 55 }} 48 L{{ $bodyEnd - 27 }} 18 L{{ $bodyEnd - 12 }} 18 L{{ $bodyEnd - 20 }} 50 Z"
                      fill="url(#wingGradient-{{ $svgKey }})" stroke="#91a8c0" stroke-width="1"/>
                <path d="M{{ $bodyEnd - 50 }} 76 L{{ $bodyEnd - 20 }} 91 L{{ $bodyEnd - 8 }} 88 L{{ $bodyEnd - 25 }} 73 Z"
                      fill="url(#wingGradient-{{ $svgKey }})" stroke="#91a8c0" stroke-width="1"/>

                <path d="M{{ $wingX - 25 }} 59 L{{ $wingX - 82 }} 96 L{{ $wingX - 60 }} 99 L{{ $wingX + 16 }} 68 Z"
                      fill="url(#wingGradient-{{ $svgKey }})" stroke="#91a8c0" stroke-width="1"/>
                <path d="M{{ $wingX - 7 }} 58 L{{ $wingX + 55 }} 95 L{{ $wingX + 78 }} 92 L{{ $wingX + 25 }} 65 Z"
                      fill="#c9d7e6" stroke="#91a8c0" stroke-width="1"/>

                @if($group === 'turboprop')
                    @foreach([$wingX - 22, $wingX + 22] as $engineX)
                        <g>
                            <ellipse cx="{{ $engineX }}" cy="72" rx="15" ry="9" fill="#b9c8d8" stroke="#8399b0"/>
                            <circle cx="{{ $engineX - 10 }}" cy="72" r="3" fill="#40556d"/>
                            <path d="M{{ $engineX - 10 }} 54 L{{ $engineX - 10 }} 90 M{{ $engineX - 26 }} 61 L{{ $engineX + 6 }} 83 M{{ $engineX - 26 }} 83 L{{ $engineX + 6 }} 61"
                                  stroke="#6c8198" stroke-width="2" stroke-linecap="round"/>
                        </g>
                    @endforeach
                @else
                    @php($engineXs = $group === 'widebody' || $group === 'jumbo' ? [$wingX - 34, $wingX + 34] : [$wingX - 22, $wingX + 24])
                    @foreach($engineXs as $engineX)
                        <g>
                            <ellipse cx="{{ $engineX }}" cy="82" rx="{{ $group === 'widebody' || $group === 'jumbo' ? 18 : 14 }}" ry="{{ $group === 'widebody' || $group === 'jumbo' ? 10 : 8 }}" fill="#b9c8d8" stroke="#8399b0"/>
                            <ellipse cx="{{ $engineX - 8 }}" cy="82" rx="5" ry="6" fill="#536b84"/>
                        </g>
                    @endforeach
                @endif

                <path d="M39 57 L54 53 L63 55 L57 61 L41 62 Z" fill="#294b70"/>
                <path d="M39 68 L57 67 L63 72 L53 74 L39 72 Z" fill="#294b70"/>

                @for($i = 0; $i < $windowCount; $i++)
                    @php($x = 70 + ($i * (($bodyLength - 78) / max(1, $windowCount - 1))))
                    <rect x="{{ $x }}" y="{{ $group === 'jumbo' ? 55 : 57 }}" width="5.2" height="3.8" rx="1.7" fill="#315b86"/>
                    @if($group === 'jumbo' && $i > 2 && $i < $windowCount - 3)
                        <rect x="{{ $x }}" y="47" width="4.4" height="3.2" rx="1.4" fill="#315b86"/>
                    @endif
                @endfor

                <path d="M63 76 L{{ $bodyEnd - 24 }} 76" stroke="currentColor" stroke-width="2.2" opacity=".72"/>
            </g>
        </svg>
    </div>

    @if($label)
        <span class="aircraft-visual-label">{{ $label }}</span>
    @endif

    @if($imageEndpoint)
        <a
            class="aircraft-photo-credit"
            data-aircraft-photo-credit
            href="#"
            target="_blank"
            rel="noopener noreferrer"
            aria-label="Bildquelle öffnen"
        ></a>
    @endif
</div>
