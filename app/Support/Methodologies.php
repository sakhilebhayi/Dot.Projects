<?php

namespace App\Support;

/**
 * How a project is run (R-34 P13a, brief §5.2). A methodology never forces a structure on a project: it says how the
 * project is run and where its work is best followed, and a template may bring the structure that suits it (sprints
 * as milestones, stage gates, a dated phase plan). Every project can still use every view.
 */
final class Methodologies
{
    /** key => [label, what it means here, the view that fits it (route name)] */
    public const ALL = [
        'waterfall' => ['Waterfall', 'Phases run one after another to a planned finish; follow it on the schedule.', 'projects.schedule'],
        'agile' => ['Agile', 'Work is planned in short cycles and reprioritised as it goes; follow it on the board.', 'projects.board'],
        'scrum' => ['Scrum', 'Sprints are the milestones, each a fixed length with its own goal; follow the sprint on the board.', 'projects.board'],
        'kanban' => ['Kanban', 'Work flows across the board with no fixed cycles; keep work in progress small.', 'projects.board'],
        'hybrid' => ['Hybrid', 'Phases and gates on the schedule, with the work inside each phase run on the board.', 'projects.schedule'],
        'lean' => ['Lean', 'Small improvements, measured as you go; follow the flow of work on the board.', 'projects.board'],
        'cpm' => ['Critical path', 'The chain of dependent work decides the finish; follow the critical path on the schedule.', 'projects.schedule'],
        'stage_gate' => ['Stage-gate', 'Each stage ends at a gate where the project is approved to go on; follow it under gates.', 'projects.gates'],
        'milestone' => ['Milestone-driven', 'The project is measured by its milestones landing on time; follow them on the overview.', 'projects.show'],
        'custom' => ['Your own', 'Run the way your business works; every view is available.', 'projects.show'],
    ];

    /** @return array<string, string> key => label */
    public static function options(): array
    {
        return array_map(fn (array $m) => $m[0], self::ALL);
    }

    public static function label(?string $key): ?string
    {
        return $key !== null ? (self::ALL[$key][0] ?? null) : null;
    }
}
