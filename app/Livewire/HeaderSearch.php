<?php

namespace App\Livewire;

use App\Models\User;
use App\Services\CommandSearch;
use Illuminate\View\View;
use Livewire\Component;

/**
 * The search in the top bar (the shared dot-ui pill, typed into, with its results under it), on every page.
 *
 * It asks the same question as the command palette (Ctrl or Cmd and K): App\Services\CommandSearch, so what it finds and who
 * may see it is decided in one place (the team and private-project scopes, saved reports by team). Nothing is queried until
 * the box has been focused, so a page that never uses the search carries no extra queries.
 */
class HeaderSearch extends Component
{
    public string $query = '';

    /** Set when the box is first focused. */
    public bool $touched = false;

    public function touch(): void
    {
        $this->touched = true;
    }

    /** Enter with nothing highlighted and no row: nothing happens (the first row is opened by the pill's own script). */
    public function submit(): void
    {
        // The pill is a GET-less form: Enter only opens a row. The box stays as it is.
    }

    public function render(CommandSearch $search): View
    {
        /** @var User $me */
        $me = auth()->user();

        return view('livewire.header-search', [
            'groups' => $this->touched ? $search->search($me, $this->query) : [],
            'typed' => trim($this->query) !== '',
        ]);
    }
}
