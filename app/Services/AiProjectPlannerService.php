<?php

namespace App\Services;

use App\Models\AiPlanLog;
use App\Models\Project;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiProjectPlannerService
{
    private string $apiKey;

    private string $model;

    public function __construct()
    {
        $this->apiKey = config('services.anthropic.api_key', '');
        $this->model = config('services.anthropic.model', 'claude-sonnet-4-6');
    }

    public function isConfigured(): bool
    {
        return ! empty($this->apiKey);
    }

    /**
     * Generate a project plan (milestones + tasks) from a description.
     * Returns ['milestones' => [['title', 'description', 'tasks' => [['title', 'priority']]]]]
     * or null on failure.
     *
     * @return array<string, mixed>|null
     */
    public function generatePlan(Project $project, int $userId): ?array
    {
        $prompt = $this->buildPrompt($project);

        if (! $this->isConfigured()) {
            return $this->mockPlan($project);
        }

        $response = Http::withToken($this->apiKey)
            ->timeout(30)
            ->post('https://api.anthropic.com/v1/messages', [
                'model' => $this->model,
                'max_tokens' => 1500,
                'messages' => [['role' => 'user', 'content' => $prompt]],
            ]);

        if (! $response->successful()) {
            Log::error('AiProjectPlanner API error', ['status' => $response->status()]);

            return null;
        }

        $text = $response->json('content.0.text', '');
        $plan = $this->parsePlan($text);

        AiPlanLog::create([
            'project_id' => $project->id,
            'user_id' => $userId,
            'prompt' => $prompt,
            'response' => $text,
            'tokens_used' => $response->json('usage.output_tokens'),
        ]);

        return $plan;
    }

    private function buildPrompt(Project $project): string
    {
        return <<<PROMPT
You are a project planning assistant. Generate a structured project plan for the following project.

Project: {$project->name}
Description: {$project->description}

Return a JSON object with this exact structure:
{
  "milestones": [
    {
      "title": "Milestone title",
      "description": "Brief description",
      "tasks": [
        {"title": "Task title", "priority": "medium"}
      ]
    }
  ]
}

Create 3-5 milestones with 3-6 tasks each. Priority must be one of: low, medium, high, urgent.
Return only the JSON object, no other text.
PROMPT;
    }

    /** @return array<string, mixed>|null */
    private function parsePlan(string $text): ?array
    {
        $json = trim($text);
        // Strip markdown code block if present
        if (str_starts_with($json, '```')) {
            $json = preg_replace('/^```[a-z]*\n?/', '', $json);
            $json = preg_replace('/\n?```$/', '', $json);
        }

        $data = json_decode(trim($json ?? ''), true);
        if (! is_array($data) || ! isset($data['milestones'])) {
            return null;
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function mockPlan(Project $project): array
    {
        return [
            'milestones' => [
                [
                    'title' => 'Discovery & Planning',
                    'description' => 'Define scope, requirements, and architecture.',
                    'tasks' => [
                        ['title' => 'Gather requirements', 'priority' => 'high'],
                        ['title' => 'Define technical architecture', 'priority' => 'high'],
                        ['title' => 'Create project timeline', 'priority' => 'medium'],
                    ],
                ],
                [
                    'title' => 'Core Development',
                    'description' => 'Build the main features of '.$project->name.'.',
                    'tasks' => [
                        ['title' => 'Set up development environment', 'priority' => 'high'],
                        ['title' => 'Implement core functionality', 'priority' => 'high'],
                        ['title' => 'Write unit tests', 'priority' => 'medium'],
                    ],
                ],
                [
                    'title' => 'Testing & Launch',
                    'description' => 'QA, bug fixes, and production deployment.',
                    'tasks' => [
                        ['title' => 'Conduct QA testing', 'priority' => 'high'],
                        ['title' => 'Fix identified bugs', 'priority' => 'urgent'],
                        ['title' => 'Deploy to production', 'priority' => 'high'],
                    ],
                ],
            ],
        ];
    }
}
