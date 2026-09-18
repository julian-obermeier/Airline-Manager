@props(['name', 'size' => 18])

@php
$paths = [
    'dashboard' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
    'operations' => '<path d="M3 12h18"/><path d="M12 3v18"/><path d="m5 7 2 2 3-4"/><path d="m14 17 2 2 3-4"/>',
    'schedule' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/><path d="m9 15 2 2 4-4"/>',
    'map' => '<polygon points="3 6 9 3 15 6 21 3 21 18 15 21 9 18 3 21 3 6"/><path d="M9 3v15M15 6v15"/>',
    'airport' => '<path d="M2 16h20"/><path d="m8 16 4-12 4 12"/><path d="M5 20h14"/>',
    'fleet' => '<path d="M22 16.5 13.5 12V4.5a1.5 1.5 0 0 0-3 0V12L2 16.5v2l8.5-2V21l-2 1v1l3.5-1 3.5 1v-1l-2-1v-4.5l8.5 2z"/>',
    'maintenance' => '<path d="M14.7 6.3a4 4 0 0 0-5-5L7 4l3 3 2.7-2.7a4 4 0 0 0 2 2z"/><path d="m5 19 9-9"/><path d="M3 21l2-5 3 3-5 2z"/>',
    'crew' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
    'revenue' => '<path d="M3 3v18h18"/><path d="m7 15 4-4 3 3 5-7"/><path d="M17 7h2v2"/>',
    'market' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a15 15 0 0 1 0 18M12 3a15 15 0 0 0 0 18"/>',
    'marketing' => '<path d="m3 11 18-5v12L3 13z"/><path d="M11 14v6a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2v-7"/>',
    'finance' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M7 15h2M13 15h4"/>',
    'world' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c3 3 4 6 4 9s-1 6-4 9c-3-3-4-6-4-9s1-6 4-9z"/>',
    'plane' => '<path d="M22 16.5 13.5 12V4.5a1.5 1.5 0 0 0-3 0V12L2 16.5v2l8.5-2V21l-2 1v1l3.5-1 3.5 1v-1l-2-1v-4.5l8.5 2z"/>',
    'route' => '<circle cx="5" cy="18" r="2"/><circle cx="19" cy="6" r="2"/><path d="M7 18c7 0 4-12 10-12"/>',
    'cash' => '<circle cx="12" cy="12" r="9"/><path d="M16 8h-6a2 2 0 1 0 0 4h4a2 2 0 1 1 0 4H8M12 6v12"/>',
    'status' => '<circle cx="12" cy="12" r="9"/><path d="m8 12 2.5 2.5L16 9"/>',
    'arrow' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
    'logout' => '<path d="M10 17l5-5-5-5"/><path d="M15 12H3"/><path d="M13 3h6a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-6"/>',
];
@endphp

<svg {{ $attributes->merge(['class' => 'icon']) }} width="{{ $size }}" height="{{ $size }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    {!! $paths[$name] ?? $paths['status'] !!}
</svg>
