{{-- R-54: every sign-in failure ends here, in words, with one next step and no automatic redirect. --}}
<x-guest-layout>
    <x-authentication-card>
        <x-slot name="logo">
            <x-authentication-card-logo />
        </x-slot>

        <div class="sso-claim" role="alert">
            <h1 class="pg-title" style="font-size:22px">{{ $headline }}</h1>
            <p class="sso-lede">{{ $body }}</p>
            <div class="sso-actions">
                @if ($retry)<a class="pg-btn pg-btn--primary sso-submit" href="{{ $retryUrl }}">Start again</a>@endif
                <a class="pg-btn pg-btn--secondary sso-submit" href="{{ $homeUrl }}">Back to Dot.Projects</a>
            </div>
            <p class="pg-help">Reference: {{ $code }}</p>
        </div>
    </x-authentication-card>
</x-guest-layout>
