<?php

namespace App\Support;

/**
 * The words for a company that does not hold Dot.Projects (R-50), from InfoDot's own reason: the `access`
 * reason at sign-in or the `status` of this platform's row during a session. Unknown reasons read as
 * "not switched on", the plainest true statement.
 */
class EntitlementRefusal
{
    /**
     * @return array{title: string, message: string}
     */
    public static function words(?string $reason, ?string $company = null): array
    {
        $for = filled($company) ? $company : 'your company';

        return match ($reason) {
            'suspended' => [
                'title' => 'Dot.Projects is suspended for '.$for,
                'message' => 'Nothing has been deleted. Projects, schedules and documents are kept as they were and open again when the suspension is lifted in InfoDot.',
            ],
            'payment_required' => [
                'title' => 'Dot.Projects needs a payment for '.$for,
                'message' => 'Nothing has been deleted. Projects, schedules and documents are kept as they were and open again once the payment is settled in InfoDot.',
            ],
            'subscription_expired', 'expired', 'trial_ended', 'cancelled' => [
                'title' => 'Dot.Projects has ended for '.$for,
                'message' => 'Nothing has been deleted. Projects, schedules and documents are kept as they were and open again when Dot.Projects is renewed in InfoDot.',
            ],
            'activation_pending' => [
                'title' => 'Dot.Projects is not switched on yet for '.$for,
                'message' => 'The request to switch it on is still waiting in InfoDot. This page opens as soon as it is approved.',
            ],
            'company_required', 'no_personal_access' => [
                'title' => 'Dot.Projects needs a company that has switched it on',
                'message' => 'A personal workspace does not include Dot.Projects. Create or join a company in InfoDot and switch Dot.Projects on for it. Nothing kept here has been deleted.',
            ],
            'not_permitted_for_you' => [
                'title' => 'Dot.Projects is not switched on for you',
                'message' => ucfirst($for).' gives Dot.Projects to chosen people. Ask a company administrator to add you in InfoDot.',
            ],
            'temporarily_unavailable', 'not_launched' => [
                'title' => 'Dot.Projects is not available right now',
                'message' => 'Nothing has been deleted. InfoDot shows when Dot.Projects can be opened again.',
            ],
            default => [
                'title' => 'Dot.Projects is not switched on for '.$for,
                'message' => 'A company administrator can switch it on in InfoDot. Anything '.$for.' already keeps here is untouched.',
            ],
        };
    }
}
