<?php

namespace App\Services;

use App\Models\Project;
use Illuminate\Validation\ValidationException;

/**
 * A project's risk matrix: how many steps probability and impact have (3, 4 or 5), what each step
 * is called, and where the score (probability x impact) turns medium, high and critical. Stored on
 * the project as JSON; a project without one uses the 5 x 5 default.
 */
final class RiskMatrix
{
    public const BANDS = ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'critical' => 'Critical'];

    private const DEFAULTS = [
        3 => [
            'probability' => ['Unlikely', 'Possible', 'Likely'],
            'impact' => ['Minor', 'Moderate', 'Major'],
            'bands' => ['medium' => 3, 'high' => 6, 'critical' => 9],
        ],
        4 => [
            'probability' => ['Unlikely', 'Possible', 'Likely', 'Almost certain'],
            'impact' => ['Minor', 'Moderate', 'Major', 'Severe'],
            'bands' => ['medium' => 3, 'high' => 6, 'critical' => 12],
        ],
        5 => [
            'probability' => ['Rare', 'Unlikely', 'Possible', 'Likely', 'Almost certain'],
            'impact' => ['Insignificant', 'Minor', 'Moderate', 'Major', 'Severe'],
            'bands' => ['medium' => 5, 'high' => 10, 'critical' => 15],
        ],
    ];

    /**
     * @param  list<string>  $probability  labels, least likely first
     * @param  list<string>  $impact  labels, smallest first
     * @param  array{medium: int, high: int, critical: int}  $bands  the lowest score of each band
     */
    public function __construct(
        public readonly int $size,
        public readonly array $probability,
        public readonly array $impact,
        public readonly array $bands,
    ) {}

    public static function default(int $size = 5): self
    {
        $d = self::DEFAULTS[$size];

        return new self($size, $d['probability'], $d['impact'], $d['bands']);
    }

    public static function forProject(Project $project): self
    {
        $raw = $project->risk_matrix;
        if (! is_array($raw)) {
            return self::default();
        }
        try {
            return self::fromArray($raw);
        } catch (ValidationException) {
            return self::default();
        }
    }

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        $size = (int) ($raw['size'] ?? 0);
        $fail = fn (string $message) => throw ValidationException::withMessages(['matrix' => $message]);
        if (! isset(self::DEFAULTS[$size])) {
            $fail('The matrix has 3, 4 or 5 steps.');
        }
        $labels = function ($list) use ($size, $fail): array {
            $list = array_values(array_map(fn ($l) => trim((string) $l), is_array($list) ? $list : []));
            if (count($list) !== $size || in_array('', $list, true)) {
                $fail('Every step needs a name.');
            }
            foreach ($list as $l) {
                if (mb_strlen($l) > 40) {
                    $fail('Step names are 40 characters at most.');
                }
            }

            return $list;
        };
        $p = $labels($raw['probability'] ?? null);
        $i = $labels($raw['impact'] ?? null);
        $b = array_map('intval', (array) ($raw['bands'] ?? []));
        $m = $b['medium'] ?? 0;
        $h = $b['high'] ?? 0;
        $c = $b['critical'] ?? 0;
        if (! ($m >= 2 && $m < $h && $h < $c && $c <= $size * $size)) {
            $fail('Each band must start above the one before it, from 2 up to '.($size * $size).'.');
        }

        return new self($size, $p, $i, ['medium' => $m, 'high' => $h, 'critical' => $c]);
    }

    /** @return array{size: int, probability: list<string>, impact: list<string>, bands: array{medium: int, high: int, critical: int}} */
    public function toArray(): array
    {
        return ['size' => $this->size, 'probability' => $this->probability, 'impact' => $this->impact, 'bands' => $this->bands];
    }

    public function band(int $score): string
    {
        return match (true) {
            $score >= $this->bands['critical'] => 'critical',
            $score >= $this->bands['high'] => 'high',
            $score >= $this->bands['medium'] => 'medium',
            default => 'low',
        };
    }

    public function probabilityLabel(?int $step): string
    {
        return $step ? ($this->probability[$step - 1] ?? (string) $step) : '–';
    }

    public function impactLabel(?int $step): string
    {
        return $step ? ($this->impact[$step - 1] ?? (string) $step) : '–';
    }
}
