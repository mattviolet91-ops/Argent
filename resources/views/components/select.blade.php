@props(['name', 'label', 'options', 'value' => null, 'placeholder' => '—', 'hint' => null, 'class' => ''])
<div class="field {{ $class }} @error($name) has-error @enderror">
    <label for="{{ $name }}">{{ $label }}</label>
    <select id="{{ $name }}" name="{{ $name }}" {{ $attributes }}>
        @if ($placeholder !== false)<option value="">{{ $placeholder }}</option>@endif
        @foreach ($options as $key => $text)
            <option value="{{ $key }}" @selected((string) old($name, $value) === (string) $key)>{{ $text }}</option>
        @endforeach
    </select>
    @if ($hint)<span class="hint">{{ $hint }}</span>@endif
    @error($name)<span class="error">{{ $message }}</span>@enderror
</div>
