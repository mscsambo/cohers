{{-- Shared scoped filter bar. Expects $districts, $categories, optional $f, $extra --}}
@php $f = $f ?? request()->all(); @endphp
<form method="GET" class="mb-5 grid grid-cols-2 gap-3 rounded-xl border border-slate-200 bg-white p-3 shadow-sm sm:grid-cols-3 lg:grid-cols-6">
    @if ($districts->count() > 1)
        <div>
            <label class="label">District</label>
            <select name="district_id" class="input">
                <option value="">All in scope</option>
                @foreach ($districts as $d)
                    <option value="{{ $d->uuid }}" @selected(request('district_id') === $d->uuid)>{{ $d->name_en }}</option>
                @endforeach
            </select>
        </div>
    @endif
    <div>
        <label class="label">Category</label>
        <select name="category_id" class="input">
            <option value="">All</option>
            @foreach ($categories as $c)
                <option value="{{ $c->uuid }}" @selected(request('category_id') === $c->uuid)>{{ $c->name_en }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="label">Priority</label>
        <select name="priority" class="input">
            <option value="">All</option>
            @foreach (\App\Enums\Priority::cases() as $p)
                <option @selected(($f['priority'] ?? '') === $p->value)>{{ $p->value }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="label">From</label>
        <input type="date" name="from" value="{{ $f['from'] ?? '' }}" class="input">
    </div>
    <div>
        <label class="label">To</label>
        <input type="date" name="to" value="{{ $f['to'] ?? '' }}" class="input">
    </div>
    {{ $extra ?? '' }}
    <div class="flex items-end gap-2">
        <button class="btn btn-primary flex-1 justify-center">Apply</button>
        <a href="{{ url()->current() }}" class="btn btn-ghost">Reset</a>
    </div>
</form>
