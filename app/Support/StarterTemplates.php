<?php

namespace App\Support;

/**
 * Starter templates for the industries the brief names (R-34 P13a, brief §15): a reasonable first structure for a
 * kind of project, offered beside the business's own templates and meant to be edited. They hold structure only,
 * never facts about a business: no people, no amounts, no dates beyond how long each piece of work usually takes
 * relative to the start. A business makes one its own by creating a project from it, shaping that project, and
 * saving it as a template.
 *
 * Tasks within a phase follow one another unless marked as running alongside the one before; each phase starts
 * when the one before it ends.
 */
final class StarterTemplates
{
    public const INDUSTRIES = [
        'mining' => 'Mining and natural resources',
        'construction' => 'Construction and engineering',
        'technology' => 'Technology',
        'manufacturing' => 'Manufacturing',
        'services' => 'Professional services',
        'general' => 'General business',
    ];

    /** @return array<string, array{name: string, industry: string, methodology: string, description: string, structure: array<string, mixed>}> */
    public static function all(): array
    {
        return [
            'shutdown' => [
                'name' => 'Planned maintenance shutdown',
                'industry' => 'mining',
                'methodology' => 'cpm',
                'description' => 'A plant or section shut down for a fixed window: plan the work, prepare, isolate, execute, start up.',
                'structure' => self::plan([
                    ['Plan the shutdown', [['Agree the scope and the list of work', 5], ['Name the shutdown team and contractors', 3, true], ['Write the isolation and permit plan', 4], ['Order spares and consumables', 2, true]]],
                    ['Prepare', [['Inspect the equipment before the window', 3], ['Stage spares, tools and scaffolding', 3, true], ['Brief every contractor on site safety', 1]]],
                    ['Execute the work', [['Isolate and lock out', 1], ['Carry out the work packages', 5], ['Check the quality of completed work', 1], ['Remove isolations', 1]]],
                    ['Start up and close', [['Run the commissioning tests', 2], ['Hand back to operations', 1], ['Review the shutdown and record lessons', 2]]],
                ], [
                    'gates' => [['title' => 'Ready to shut down', 'phase' => 1, 'checks' => ['Every work package has an owner and a method statement', 'Spares and tools are on site', 'Isolation plan is signed off', 'Contractors are inducted']],
                        ['title' => 'Ready to restart', 'phase' => 2, 'checks' => ['All work packages are complete or deferred with a reason', 'Isolations are removed and signed off', 'The area is clear of people and tools']]],
                    'risks' => [['title' => 'A contractor is not available on the day', 'category' => 'suppliers', 'probability' => 3, 'impact' => 4],
                        ['title' => 'Critical spares arrive late', 'category' => 'suppliers', 'probability' => 3, 'impact' => 4],
                        ['title' => 'Someone is injured during isolation work', 'category' => 'safety', 'probability' => 2, 'impact' => 5],
                        ['title' => 'The work overruns the shutdown window', 'category' => 'schedule', 'probability' => 3, 'impact' => 4]],
                    'budget' => [['category' => 'contractors', 'name' => 'Contractors'], ['category' => 'materials', 'name' => 'Spares and consumables'], ['category' => 'equipment', 'name' => 'Equipment hire'], ['category' => 'labour', 'name' => 'Own labour']],
                    'labels' => ['Safety', 'Contractor', 'Critical spare'],
                ]),
            ],
            'equipment-deployment' => [
                'name' => 'Equipment deployment',
                'industry' => 'mining',
                'methodology' => 'hybrid',
                'description' => 'New equipment chosen, delivered, commissioned and handed to the people who will run it.',
                'structure' => self::plan([
                    ['Select and order', [['Write the requirement', 5], ['Compare suppliers and quotes', 10], ['Approve and place the order', 3]]],
                    ['Prepare the site', [['Prepare the foundations and services', 15], ['Plan delivery and access', 3, true]]],
                    ['Deliver and commission', [['Receive and inspect the equipment', 2], ['Install', 5], ['Commission and test', 5]]],
                    ['Train and hand over', [['Train operators and maintainers', 5], ['Hand over with manuals and spares list', 2]]],
                ], [
                    'gates' => [['title' => 'Ready to order', 'phase' => 0, 'checks' => ['The requirement is agreed', 'The budget is approved', 'The supplier is chosen on a written comparison']]],
                    'risks' => [['title' => 'Delivery is later than quoted', 'category' => 'suppliers', 'probability' => 3, 'impact' => 3],
                        ['title' => 'The site is not ready when the equipment arrives', 'category' => 'schedule', 'probability' => 2, 'impact' => 3]],
                    'budget' => [['category' => 'equipment', 'name' => 'Equipment'], ['category' => 'contractors', 'name' => 'Installation'], ['category' => 'operational', 'name' => 'Training']],
                    'labels' => ['Supplier', 'Site'],
                ]),
            ],
            'works-package' => [
                'name' => 'Building works package',
                'industry' => 'construction',
                'methodology' => 'stage_gate',
                'description' => 'From design and approvals to procurement, site works, inspections and handover.',
                'structure' => self::plan([
                    ['Design and approvals', [['Agree the brief with the client', 5], ['Prepare the drawings', 20], ['Submit plans for approval', 2], ['Prepare the bill of quantities', 10, true]]],
                    ['Procurement', [['Tender the subcontract packages', 15], ['Appoint subcontractors', 5], ['Order long-lead materials', 5, true]]],
                    ['Site works', [['Establish the site', 5], ['Foundations', 15], ['Structure', 30], ['Services and finishes', 30]]],
                    ['Inspections and handover', [['Inspections and snag list', 5], ['Clear the snags', 10], ['Hand over to the client', 2]]],
                ], [
                    'gates' => [['title' => 'Ready to build', 'phase' => 1, 'checks' => ['Plans are approved', 'The main contract is signed', 'The site is handed over', 'The health and safety plan is agreed']],
                        ['title' => 'Practical completion', 'phase' => 3, 'checks' => ['The snag list is cleared or agreed', 'Certificates and test results are handed over', 'The client accepts the works']]],
                    'risks' => [['title' => 'Rain delays site works', 'category' => 'external', 'probability' => 3, 'impact' => 3],
                        ['title' => 'Material prices rise after the tender', 'category' => 'cost', 'probability' => 3, 'impact' => 3],
                        ['title' => 'The client asks for changes during the works', 'category' => 'scope', 'probability' => 3, 'impact' => 3],
                        ['title' => 'A subcontractor cannot staff the work', 'category' => 'suppliers', 'probability' => 2, 'impact' => 4]],
                    'budget' => [['category' => 'materials', 'name' => 'Materials'], ['category' => 'contractors', 'name' => 'Subcontractors'], ['category' => 'equipment', 'name' => 'Plant hire'], ['category' => 'operational', 'name' => 'Site establishment']],
                    'labels' => ['Variation', 'Inspection', 'Procurement'],
                ]),
            ],
            'scrum-release' => [
                'name' => 'Software release in sprints',
                'industry' => 'technology',
                'methodology' => 'scrum',
                'description' => 'Four two-week sprints, each planned, built, tested and reviewed, then a release.',
                'structure' => self::plan(array_merge(array_map(fn ($n) => ["Sprint {$n}", [['Plan the sprint', 1], ['Build and review the sprint\'s stories', 10], ['Test the sprint\'s work', 2], ['Sprint review and retrospective', 1]]], range(1, 4)),
                    [['Release', [['Test the release candidate', 3], ['Deploy to production', 1], ['Watch production for a week', 5]]]]), [
                        'risks' => [['title' => 'Scope grows during the sprints', 'category' => 'scope', 'probability' => 3, 'impact' => 3],
                            ['title' => 'A key developer is unavailable', 'category' => 'resources', 'probability' => 2, 'impact' => 4],
                            ['title' => 'A production incident follows the release', 'category' => 'technical', 'probability' => 2, 'impact' => 4]],
                        'labels' => ['Story', 'Bug', 'Tech debt'],
                    ]),
            ],
            'equipment-installation' => [
                'name' => 'Production equipment installation',
                'industry' => 'manufacturing',
                'methodology' => 'waterfall',
                'description' => 'A new line or machine specified, installed and commissioned with as little lost production as possible.',
                'structure' => self::plan([
                    ['Specify and order', [['Agree the specification with production', 5], ['Order the equipment', 3]]],
                    ['Prepare the floor', [['Plan the downtime with production', 3], ['Prepare foundations, power and air', 10, true]]],
                    ['Install', [['Move and set the equipment', 3], ['Connect services', 3]]],
                    ['Commission and train', [['Trial runs and quality checks', 5], ['Train operators', 3, true], ['Release to production', 1]]],
                ], [
                    'gates' => [['title' => 'Ready to run production', 'phase' => 3, 'checks' => ['Trial output meets the quality standard', 'Operators are trained', 'Maintenance schedule is set up']]],
                    'risks' => [['title' => 'Installation takes longer than the planned downtime', 'category' => 'schedule', 'probability' => 3, 'impact' => 4],
                        ['title' => 'Trial output misses the quality standard', 'category' => 'quality', 'probability' => 2, 'impact' => 3]],
                    'budget' => [['category' => 'equipment', 'name' => 'Equipment'], ['category' => 'contractors', 'name' => 'Installation'], ['category' => 'labour', 'name' => 'Own labour']],
                    'labels' => ['Downtime', 'Quality'],
                ]),
            ],
            'client-engagement' => [
                'name' => 'Client engagement',
                'industry' => 'services',
                'methodology' => 'milestone',
                'description' => 'A client project from kick-off through discovery and delivery to the client\'s approval.',
                'structure' => self::plan([
                    ['Kick-off', [['Confirm scope, fees and contacts', 2], ['Kick-off meeting', 1]]],
                    ['Discovery', [['Interviews and information gathering', 10], ['Findings agreed with the client', 3]]],
                    ['Delivery', [['Prepare the deliverables', 15], ['Internal quality review', 2]]],
                    ['Approval and close', [['Present to the client', 1], ['Make the agreed changes', 5], ['Client sign-off', 2], ['Final invoice', 1]]],
                ], [
                    'gates' => [['title' => 'Client approves the deliverables', 'phase' => 3, 'checks' => ['The client has the final version', 'Changes requested are made or agreed', 'Sign-off is in writing']]],
                    'risks' => [['title' => 'The client is slow to give information', 'category' => 'stakeholders', 'probability' => 3, 'impact' => 3],
                        ['title' => 'Work beyond the agreed scope is requested', 'category' => 'scope', 'probability' => 3, 'impact' => 3]],
                    'budget' => [['category' => 'labour', 'name' => 'Consultant time'], ['category' => 'operational', 'name' => 'Travel']],
                    'labels' => ['Billable', 'Waiting on client'],
                ]),
            ],
            'marketing-campaign' => [
                'name' => 'Marketing campaign',
                'industry' => 'general',
                'methodology' => 'kanban',
                'description' => 'Plan the campaign, make the material, run it and measure what it did.',
                'structure' => self::plan([
                    ['Plan', [['Agree the goal and the audience', 2], ['Choose channels and the budget', 3]]],
                    ['Create', [['Write the copy', 5], ['Design the material', 5, true], ['Approve the material', 2]]],
                    ['Run', [['Launch', 1], ['Run and adjust', 20]]],
                    ['Measure', [['Report results against the goal', 3]]],
                ], [
                    'budget' => [['category' => 'operational', 'name' => 'Media'], ['category' => 'contractors', 'name' => 'Design and production']],
                    'labels' => ['Copy', 'Design', 'Channel'],
                ]),
            ],
            'product-launch' => [
                'name' => 'New product launch',
                'industry' => 'general',
                'methodology' => 'milestone',
                'description' => 'Get a new product or service ready to sell: offer, operations, people and launch.',
                'structure' => self::plan([
                    ['Shape the offer', [['Define the product and its price', 10], ['Test it with a few customers', 10]]],
                    ['Get ready to sell', [['Set up stock or delivery', 15], ['Train the people who sell and support it', 5, true], ['Prepare launch material', 10, true]]],
                    ['Launch', [['Launch', 1], ['Follow up early customers', 10]]],
                ], [
                    'gates' => [['title' => 'Ready to launch', 'phase' => 1, 'checks' => ['Customers in the test would buy it', 'Delivery or stock is in place', 'The team can sell and support it']]],
                    'risks' => [['title' => 'Customers do not take to the offer', 'category' => 'external', 'probability' => 3, 'impact' => 4]],
                    'budget' => [['category' => 'operational', 'name' => 'Launch marketing'], ['category' => 'materials', 'name' => 'First stock']],
                ]),
            ],
            'lean-improvement' => [
                'name' => 'Internal improvement',
                'industry' => 'general',
                'methodology' => 'lean',
                'description' => 'Understand how something works today, try a change, measure it and make it the standard.',
                'structure' => self::plan([
                    ['Understand today', [['Map how the work is done now', 5], ['Measure the starting point', 5, true]]],
                    ['Try a change', [['Choose one change to try', 2], ['Run the trial', 10]]],
                    ['Measure', [['Compare against the starting point', 3]]],
                    ['Make it standard', [['Write the new way of working', 3], ['Train the people who do the work', 3]]],
                ], ['labels' => ['Waste', 'Idea']]),
            ],
        ];
    }

    /**
     * Lays the phases out on day offsets from the project's start, each task after the one before it in its phase
     * unless marked to run alongside, and each phase after the one before.
     *
     * @param  list<array{0: string, 1: list<array{0: string, 1: int, 2?: bool}>}>  $phases
     * @param  array<string, mixed>  $extra  gates (with a phase index), risks, budget, labels
     * @return array<string, mixed>
     */
    private static function plan(array $phases, array $extra): array
    {
        $out = ['v' => 1, 'phases' => [], 'tasks' => []];
        $cursor = 0;
        $chainEnd = null; // the last task on the previous phase's main line
        foreach ($phases as $p => [$title, $tasks]) {
            $end = $cursor;
            $prev = $chainEnd;
            $prevStart = $cursor;
            $prevAfter = [];
            foreach ($tasks as $t) {
                [$taskTitle, $days] = $t;
                $alongside = ($t[2] ?? false) === true;
                // Alongside: starts with the task before and waits for what that task waits for.
                $start = $alongside ? $prevStart : max($cursor, $end);
                $after = $alongside ? $prevAfter : ($prev !== null ? [$prev] : []);
                $index = count($out['tasks']);
                $out['tasks'][] = ['title' => $taskTitle, 'phase' => $p, 'start' => $start, 'due' => $start + $days, 'after' => $after];
                if (! $alongside) {
                    $prev = $index;
                    $prevAfter = $after;
                    $prevStart = $start;
                }
                $end = max($end, $start + $days);
            }
            $out['phases'][] = ['title' => $title, 'due' => $end];
            $chainEnd = $prev;
            $cursor = $end;
        }
        $out['days'] = $cursor;
        foreach ($extra['gates'] ?? [] as $g) {
            $out['gates'][] = $g + ['due' => $out['phases'][$g['phase']]['due']];
        }

        return $out + array_intersect_key($extra, array_flip(['risks', 'budget', 'labels']));
    }
}
