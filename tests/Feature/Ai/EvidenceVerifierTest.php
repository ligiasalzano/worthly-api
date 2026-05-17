<?php

use App\Ai\Agents\EvidenceVerifier;
use App\Ai\Harness\Dto\EvidenceBundle;
use App\Ai\Harness\Dto\EvidenceItem;
use App\Ai\Harness\Dto\VerificationReport;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredAgentResponse;

function makeEvidenceVerifierFake(array $structured): EvidenceVerifier
{
    return new class($structured) extends EvidenceVerifier
    {
        public function __construct(private array $structured) {}

        protected function callModel(array $structuredOutput, EvidenceBundle $bundle): StructuredAgentResponse
        {
            return new StructuredAgentResponse(
                invocationId: (string) Str::uuid7(),
                structured: $this->structured,
                text: 'fake',
                usage: new Usage(0, 0, 0, 0, 0),
                meta: new Meta,
            );
        }
    };
}

function evidenceVerifierBundle(): EvidenceBundle
{
    return new EvidenceBundle([
        new EvidenceItem('reviews', 'https://example.test/a', 'Alpha', 'snippet alpha', null, 0.8, 0.7),
        new EvidenceItem('reviews', 'https://example.test/b', 'Bravo', 'snippet bravo', null, 0.8, 0.6),
    ]);
}

it('maps each claim to one of supported|partially_supported|unsupported and exposes hasUnsupported()', function () {
    $bundle = evidenceVerifierBundle();

    $verifier = makeEvidenceVerifierFake([
        'claims' => [
            ['field' => 'summary', 'status' => 'supported', 'evidence_ids' => ['S1']],
            ['field' => 'cost_benefit', 'status' => 'partially_supported', 'evidence_ids' => ['S2']],
            ['field' => 'recommendation', 'status' => 'unsupported', 'evidence_ids' => []],
        ],
    ]);

    $report = $verifier->verify([
        'summary' => 'great battery life',
        'cost_benefit_analysis' => 'priced fairly',
        'recommendation' => ['decision' => 'buy', 'reason' => 'speculative'],
    ], $bundle);

    expect($report)->toBeInstanceOf(VerificationReport::class);
    expect($report->claims)->toHaveCount(3);
    expect($report->claims[0]['field'])->toBe('summary');
    expect($report->claims[0]['status'])->toBe('supported');
    expect($report->claims[0]['evidence_ids'])->toBe(['S1']);
    expect($report->claims[1]['status'])->toBe('partially_supported');
    expect($report->claims[2]['status'])->toBe('unsupported');
    expect($report->claims[2]['evidence_ids'])->toBe([]);
    expect($report->hasUnsupported())->toBeTrue();
    expect($report->unsupportedFields())->toBe(['recommendation']);
});

it('returns hasUnsupported() = false when every claim is supported or partially supported', function () {
    $bundle = evidenceVerifierBundle();

    $verifier = makeEvidenceVerifierFake([
        'claims' => [
            ['field' => 'summary', 'status' => 'supported', 'evidence_ids' => ['S1']],
            ['field' => 'recommendation', 'status' => 'partially_supported', 'evidence_ids' => ['S2']],
        ],
    ]);

    $report = $verifier->verify([], $bundle);

    expect($report->hasUnsupported())->toBeFalse();
    expect($report->unsupportedFields())->toBe([]);
});

it('coerces unknown statuses to unsupported and drops empty fields', function () {
    $bundle = evidenceVerifierBundle();

    $verifier = makeEvidenceVerifierFake([
        'claims' => [
            ['field' => 'summary', 'status' => 'maybe', 'evidence_ids' => ['S1']],
            ['field' => '', 'status' => 'supported', 'evidence_ids' => []],
        ],
    ]);

    $report = $verifier->verify([], $bundle);

    expect($report->claims)->toHaveCount(1);
    expect($report->claims[0]['status'])->toBe('unsupported');
    expect($report->hasUnsupported())->toBeTrue();
});
