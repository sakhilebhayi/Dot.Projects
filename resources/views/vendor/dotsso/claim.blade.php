{{-- R-54: the claim page, in The Programme. Shown when an account here already uses the email address of the InfoDot sign-in.
     Nothing on the account is replaced; the code proves the address. Variables come from the shared package. --}}
<x-guest-layout>
    <x-authentication-card>
        <x-slot name="logo">
            <x-authentication-card-logo />
        </x-slot>

        <div class="sso-claim">
            <h1 class="pg-title" style="font-size:22px">Confirm it is you</h1>
            <p class="sso-lede">An account here already uses <strong>{{ $maskedEmail }}</strong>. We sent a {{ $codeLength }}-digit code to that address. Enter it to connect the two. Your password, projects and teams stay as they are.</p>

            @if (session('dotsso_status'))
                <p class="sso-status" role="status">{{ session('dotsso_status') }}</p>
            @endif

            <form method="POST" action="{{ $verifyUrl }}" class="sso-form" novalidate>
                @csrf
                <x-label for="code" value="Code from the email" />
                <x-input id="code" name="code" type="text" inputmode="numeric" pattern="[0-9]*" autocomplete="one-time-code"
                         maxlength="{{ $codeLength }}" required autofocus
                         class="pg-input sso-code" aria-describedby="code-help code-error"
                         :aria-invalid="$errors->has('code') ? 'true' : 'false'" />
                <p id="code-help" class="pg-help">It works for {{ $minutes }} minutes. You have {{ $maxAttempts }} tries.</p>
                <p id="code-error" class="sso-error" role="alert">{{ $errors->first('code') }}</p>

                <x-button class="sso-submit">Confirm and continue</x-button>
            </form>

            <div class="sso-aside">
                <form method="POST" action="{{ $resendUrl }}">@csrf<button type="submit" class="pg-btn sso-link">Send a new code</button></form>
                <form method="POST" action="{{ $cancelUrl }}">@csrf<button type="submit" class="pg-btn sso-link">Cancel</button></form>
            </div>
        </div>
    </x-authentication-card>
</x-guest-layout>
