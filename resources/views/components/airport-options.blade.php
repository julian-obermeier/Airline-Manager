@props(['airports', 'selected' => null])

@foreach($airports->groupBy('country_code') as $countryCode => $countryAirports)
    <optgroup label="{{ $countryCode }}">
        @foreach($countryAirports as $airport)
            <option value="{{ $airport->id }}" @selected((string) $selected === (string) $airport->id)>
                {{ $airport->iata_code }} · {{ $airport->city }} · {{ $airport->name }}
            </option>
        @endforeach
    </optgroup>
@endforeach
