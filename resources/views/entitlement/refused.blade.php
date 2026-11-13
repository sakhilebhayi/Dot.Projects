{{-- R-50: the company does not hold Dot.Projects in InfoDot. Shown with HTTP 403 at sign-in and during a
     session. It carries no project data: only the reason, the one way forward (InfoDot), and, for a signed-in
     person with other companies, the switcher. The Programme (DESIGN.md): the sign-in card, one primary button. --}}
<x-guest-layout>
    <main style="min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:22px;padding:40px max(16px, env(safe-area-inset-right, 0px)) 40px max(16px, env(safe-area-inset-left, 0px))">
        <x-authentication-card-logo />

        <div class="pg-card" style="width:100%;max-width:460px;padding:26px 28px">
            <h1 class="pg-title" style="font-size:22px">{{ $title }}</h1>
            <p style="margin:10px 0 0;font-size:14px;line-height:1.5;color:var(--pg-muted);max-width:60ch">{{ $message }}</p>

            <p style="margin:22px 0 0">
                <a class="pg-btn pg-btn--primary" href="{{ $manageUrl }}" data-primary-action>Open Platforms in InfoDot</a>
            </p>

            @if (($otherCompanies ?? collect())->isNotEmpty())
                <section aria-labelledby="other-companies" style="margin-top:24px;padding-top:16px;border-top:1px solid var(--pg-rule)">
                    <h2 id="other-companies" style="font-size:14px;font-weight:600;margin:0 0 6px">Work in another of your companies</h2>
                    @foreach ($otherCompanies as $company)
                        <form method="POST" action="{{ route('current-team.update') }}" class="pg-row" style="align-items:center">
                            @method('PUT')
                            @csrf
                            <input type="hidden" name="team_id" value="{{ $company->id }}">
                            <span style="min-width:0;overflow-wrap:anywhere">{{ $company->name }}</span>
                            <button type="submit" class="pg-btn pg-btn--ghost pg-btn--sm" aria-label="Switch to {{ $company->name }}">Switch</button>
                        </form>
                    @endforeach
                </section>
            @endif
        </div>
    </main>
</x-guest-layout>
