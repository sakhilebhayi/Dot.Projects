<?php

namespace App\Console\Commands;

use App\Models\Milestone;
use App\Notifications\MilestoneDueSoonNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Notifies a project's owner and members when one of its milestones is due
 * within the next two days and hasn't been completed yet. Intended to run
 * daily (see routes/console.php). Skips a milestone if a due-soon
 * notification for it was already sent in the last 24 hours, so re-running
 * the command (or a missed schedule catching up) doesn't spam the same
 * reminder every run.
 */
class CheckMilestonesDueSoon extends Command
{
    protected $signature = 'projects:check-milestones-due';

    protected $description = 'Notify project owners and members about milestones due within the next two days.';

    public function handle(): int
    {
        $windowEnd = Carbon::today()->addDays(2)->toDateString();
        $windowStart = Carbon::today()->toDateString();

        $milestones = Milestone::query()
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<=', $windowEnd)
            ->whereDate('due_date', '>=', $windowStart)
            ->where('status', '!=', 'completed')
            ->with(['project.owner', 'project.members'])
            ->get();

        $notified = 0;

        foreach ($milestones as $milestone) {
            $project = $milestone->project;
            if (! $project) {
                continue;
            }

            $recipients = collect([$project->owner])
                ->merge($project->members)
                ->filter()
                ->unique('id');

            foreach ($recipients as $recipient) {
                // Filtered in PHP rather than a `data->milestone_id` JSON-path query so this
                // works identically regardless of the notifications.data column's declared
                // type across database drivers.
                $alreadyNotified = $recipient->notifications()
                    ->where('type', MilestoneDueSoonNotification::class)
                    ->where('created_at', '>=', Carbon::now()->subDay())
                    ->get()
                    ->contains(fn ($n) => ($n->data['milestone_id'] ?? null) === $milestone->id);

                if ($alreadyNotified) {
                    continue;
                }

                $recipient->notify(new MilestoneDueSoonNotification($milestone));
                $notified++;
            }
        }

        $this->info("Sent {$notified} milestone due-soon notification(s).");

        return self::SUCCESS;
    }
}
