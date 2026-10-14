<form method="POST" action="{{ route('company.switch') }}" class="flex items-center px-2">
    @csrf
    <select name="company_id" onchange="this.form.submit()" aria-label="Azienda"
        class="rounded-lg border border-gray-300 bg-white py-1 pe-8 ps-3 text-sm text-gray-900 dark:border-white/10 dark:bg-gray-900 dark:text-white">
        <option value="">Tutte le aziende</option>
        @foreach (\App\Models\Company::orderBy('name')->get(['id', 'name']) as $company)
            <option value="{{ $company->id }}" @selected(\App\Support\CurrentCompany::id() === $company->id)>{{ $company->name }}</option>
        @endforeach
    </select>
</form>
