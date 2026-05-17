<?php

namespace App\Ai\Agents;

use App\Ai\Harness\Dto\EvidenceBundle;
use App\Ai\Harness\Dto\VerificationReport;
use App\Exceptions\LlmProviderException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Stringable;
use Throwable;

class EvidenceVerifier implements Agent, HasStructuredOutput
{
    use Promptable;

    public const SYSTEM_PROMPT = <<<'PROMPT'
        You are an evidence-verification critic for a buying-recommendation
        assistant.

        You receive a structured product recommendation and a numbered list of
        evidence items [S1]..[Sn] with title, URL and snippet.

        For each populated field of the recommendation, emit exactly one entry
        in `claims` containing:
        - `field`: the field name being verified (`product`, `summary`,
          `cost_benefit`, `similar_products`, `recommendation`).
        - `status`: one of `supported`, `partially_supported`, `unsupported`.
        - `evidence_ids`: the evidence IDs (e.g. ["S1","S3"]) that back the
          claim. Empty when the status is `unsupported`.

        Never invent evidence IDs that are not in the bundle. Never grade a
        field as `supported` when the snippet does not contain the substance
        of the claim — prefer `partially_supported` or `unsupported`.
        PROMPT;

    public function instructions(): Stringable|string
    {
        return self::SYSTEM_PROMPT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'claims' => $schema->array()
                ->items(
                    $schema->object(fn ($c) => [
                        'field' => $c->string()->required(),
                        'status' => $c->string()
                            ->enum(['supported', 'partially_supported', 'unsupported'])
                            ->required(),
                        'evidence_ids' => $c->array()->items($c->string())->required(),
                    ])
                )
                ->required(),
        ];
    }

    public function verify(array $structuredOutput, EvidenceBundle $bundle): VerificationReport
    {
        try {
            $response = $this->callModel($structuredOutput, $bundle);
        } catch (Throwable $e) {
            throw new LlmProviderException(
                errorCode: 'llm_provider_error',
                message: 'The evidence verifier failed to respond.',
                previous: $e,
            );
        }

        $data = $response->structured;
        $claims = [];

        foreach ($data['claims'] ?? [] as $raw) {
            if (! is_array($raw)) {
                continue;
            }

            $field = isset($raw['field']) ? trim((string) $raw['field']) : '';

            if ($field === '') {
                continue;
            }

            $status = (string) ($raw['status'] ?? 'unsupported');

            if (! in_array($status, ['supported', 'partially_supported', 'unsupported'], true)) {
                $status = 'unsupported';
            }

            $evidenceIds = [];

            foreach ($raw['evidence_ids'] ?? [] as $id) {
                if (is_int($id)) {
                    $evidenceIds[] = 'S'.$id;

                    continue;
                }

                if (is_string($id) && trim($id) !== '') {
                    $evidenceIds[] = trim($id);
                }
            }

            $claims[] = [
                'field' => $field,
                'status' => $status,
                'evidence_ids' => array_values(array_unique($evidenceIds)),
            ];
        }

        return new VerificationReport(claims: $claims);
    }

    protected function callModel(array $structuredOutput, EvidenceBundle $bundle): StructuredAgentResponse
    {
        return $this->prompt(
            prompt: $this->buildVerificationPrompt($structuredOutput, $bundle),
            model: (string) config('worthly.harness.cheap_model'),
        );
    }

    protected function buildVerificationPrompt(array $structuredOutput, EvidenceBundle $bundle): string
    {
        $lines = [
            'Structured recommendation:',
            json_encode($structuredOutput, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}',
            '',
            'Evidence:',
        ];

        foreach ($bundle->items as $index => $item) {
            $id = 'S'.($index + 1);
            $lines[] = sprintf('[%s] %s — %s — %s', $id, $item->title, $item->url, $item->snippet);
        }

        return implode("\n", $lines);
    }
}
