@props(['group' => 'narrowbody', 'label' => null])

@php
$shape = match($group) {
    'turboprop' => 'M12 5v5L4 14v2l8-1v4l-2 1v1l3-1 3 1v-1l-2-1v-4l8 1v-2l-8-4V5a1 1 0 0 0-2 0Zm-6 5 2 2M18 10l-2 2',
    'regional' => 'M12 4v7L3 15v2l9-2v4l-2 1v1l2-1 2 1v-1l-2-1v-4l9 2v-2l-9-4V4a1 1 0 0 0-2 0Z',
    'widebody' => 'M12 3v8L2 16v2l10-2v4l-2 1v1l2-.7 2 .7v-1l-2-1v-4l10 2v-2l-10-5V3a1 1 0 0 0-2 0Z',
    'jumbo' => 'M12 3v8L1 16v2l11-2v4l-2 1v1l2-.7 2 .7v-1l-2-1v-4l11 2v-2l-11-5V3a1 1 0 0 0-2 0Z',
    default => 'M12 4v7L3 15v2l9-2v4l-2 1v1l2-.8 2 .8v-1l-2-1v-4l9 2v-2l-9-4V4a1 1 0 0 0-2 0Z',
};
@endphp

<div {{ $attributes->merge(['class' => 'aircraft-visual aircraft-visual-'.$group]) }}>
    <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="{{ $shape }}"/></svg>
    @if($label)<span>{{ $label }}</span>@endif
</div>
