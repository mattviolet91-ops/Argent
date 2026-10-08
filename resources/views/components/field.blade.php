@props(['name', 'label', 'hint' => null, 'type' => 'text', 'value' => null, 'required' => false, 'class' => ''])
@php
    // « company.phone » → name="company[phone]", id="company-phone", erreurs sur « company.phone ».
    $parts = explode('.', $name);
    $inputName = array_shift($parts).collect($parts)->map(fn ($p) => "[$p]")->implode('');
    $id = str_replace('.', '-', $name);
@endphp
<div class="field {{ $class }} @error($name) has-error @enderror">
    <label for="{{ $id }}">{{ $label }}@if ($required) <span aria-hidden="true">*</span>@endif</label>
    @if ($type === 'textarea')
        <textarea id="{{ $id }}" name="{{ $inputName }}" {{ $attributes }} @required($required)>{{ old($name, $value) }}</textarea>
    @else
        <input id="{{ $id }}" type="{{ $type }}" name="{{ $inputName }}" value="{{ old($name, $value) }}" {{ $attributes }} @required($required)>
    @endif
    @if ($hint)<span class="hint">{{ $hint }}</span>@endif
    @error($name)<span class="error">{{ $message }}</span>@enderror
</div>
