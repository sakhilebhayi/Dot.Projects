<?php

namespace App\Livewire\Teams;

use Illuminate\Support\Facades\Gate;
use Laravel\Jetstream\Http\Livewire\TeamMemberManager as JetstreamTeamMemberManager;

/**
 * Jetstream's team member manager authorizes add, role change and removal inside
 * its actions, but cancelTeamInvitation() and manageRole() check nothing: the page
 * only hides their buttons. The component is rendered for every member of the team,
 * so any member (a read-only viewer included) could call them straight at the
 * Livewire endpoint. These two overrides add the same gates the buttons sit behind.
 *
 * Registered over Jetstream's own alias in JetstreamServiceProvider.
 */
class TeamMemberManager extends JetstreamTeamMemberManager
{
    public function cancelTeamInvitation($invitationId)
    {
        Gate::forUser($this->user)->authorize('removeTeamMember', $this->team);

        parent::cancelTeamInvitation($invitationId);
    }

    public function manageRole($userId)
    {
        Gate::forUser($this->user)->authorize('updateTeamMember', $this->team);

        parent::manageRole($userId);
    }
}
