<?php

use App\Enums\InputType as InputTypeEnum;
use App\Models\Analysis;
use App\Models\User;
use Database\Seeders\InputTypeSeeder;
use Database\Seeders\RecommendationDecisionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakesProductReviewer;

uses(FakesProductReviewer::class);

beforeEach(function () {
    $this->seed([
        InputTypeSeeder::class,
        RecommendationDecisionSeeder::class,
    ]);

    Storage::fake('analysis_images');
});

it('accepts a jpeg upload, persists the file under analyses/ and returns 201 with image_url', function () {
    $user = User::factory()->create();
    $this->bindFakeProductReviewer();

    $upload = UploadedFile::fake()->image('original-photo.jpg', 800, 600);

    $response = $this->actingAs($user)->postJson('/api/analyses', [
        'input_type' => InputTypeEnum::Image->value,
        'image' => $upload,
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.input_type', InputTypeEnum::Image->value);

    $analysis = Analysis::first();
    expect($analysis)->not->toBeNull();
    expect($analysis->user_id)->toBe($user->id);
    expect($analysis->image_path)->toStartWith('analyses/');

    Storage::disk('analysis_images')->assertExists($analysis->image_path);

    $expectedUrl = route('analyses.image', ['analysis' => $analysis->id]);
    expect($response->json('data.image_url'))->toBe($expectedUrl);
});

it('accepts a png upload', function () {
    $user = User::factory()->create();
    $this->bindFakeProductReviewer();

    $response = $this->actingAs($user)->postJson('/api/analyses', [
        'input_type' => InputTypeEnum::Image->value,
        'image' => UploadedFile::fake()->image('photo.png', 400, 400),
    ]);

    $response->assertCreated();
    expect(Analysis::first()->image_path)->toEndWith('.png');
});

it('accepts a webp upload', function () {
    $user = User::factory()->create();
    $this->bindFakeProductReviewer();

    $response = $this->actingAs($user)->postJson('/api/analyses', [
        'input_type' => InputTypeEnum::Image->value,
        'image' => UploadedFile::fake()->image('photo.webp', 400, 400),
    ]);

    $response->assertCreated();
    expect(Analysis::first()->image_path)->toEndWith('.webp');
});

it('stores the file with a uuid-based name (no original filename leaking)', function () {
    $user = User::factory()->create();
    $this->bindFakeProductReviewer();

    $originalName = 'SUPERsecretPII_zzZZ';
    $upload = UploadedFile::fake()->image($originalName.'.jpg', 200, 200);

    $this->actingAs($user)->postJson('/api/analyses', [
        'input_type' => InputTypeEnum::Image->value,
        'image' => $upload,
    ])->assertCreated();

    $storedPath = Analysis::first()->image_path;
    $basename = pathinfo($storedPath, PATHINFO_FILENAME);

    expect($storedPath)->not->toContain($originalName);

    $nonHexChars = preg_replace('/[0-9a-f\-]/i', '', $originalName);
    foreach (str_split($nonHexChars) as $char) {
        expect($basename)->not->toContain($char);
    }

    expect($basename)->toMatch('/^[0-9a-f\-]{36}$/i');
});

it('returns 422 when input_type is image but image field is missing', function () {
    $user = User::factory()->create();
    $this->bindFakeProductReviewer();

    $response = $this->actingAs($user)->postJson('/api/analyses', [
        'input_type' => InputTypeEnum::Image->value,
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['image']);
    expect(Analysis::count())->toBe(0);
    expect(Storage::disk('analysis_images')->allFiles())->toBeEmpty();
});

it('returns 422 when the file exceeds the size limit and does not persist anything', function () {
    $user = User::factory()->create();
    $this->bindFakeProductReviewer();

    $oversized = UploadedFile::fake()->image('big.jpg')->size(9000);

    $response = $this->actingAs($user)->postJson('/api/analyses', [
        'input_type' => InputTypeEnum::Image->value,
        'image' => $oversized,
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['image']);
    expect(Analysis::count())->toBe(0);
    expect(Storage::disk('analysis_images')->allFiles())->toBeEmpty();
});

it('rejects unsupported mime types', function (string $filename, string $mime) {
    $user = User::factory()->create();
    $this->bindFakeProductReviewer();

    $upload = UploadedFile::fake()->createWithContent($filename, 'fake-content');

    $response = $this->actingAs($user)->postJson('/api/analyses', [
        'input_type' => InputTypeEnum::Image->value,
        'image' => $upload,
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['image']);
    expect(Analysis::count())->toBe(0);
})->with([
    'svg' => ['logo.svg', 'image/svg+xml'],
    'plain' => ['notes.txt', 'text/plain'],
]);

it('persists the file on a private disk (analysis_images), not on public/storage', function () {
    $user = User::factory()->create();
    $this->bindFakeProductReviewer();

    $this->actingAs($user)->postJson('/api/analyses', [
        'input_type' => InputTypeEnum::Image->value,
        'image' => UploadedFile::fake()->image('photo.jpg', 200, 200),
    ])->assertCreated();

    $path = Analysis::first()->image_path;

    Storage::disk('analysis_images')->assertExists($path);

    $config = config('filesystems.disks.analysis_images');
    expect($config['driver'])->toBe('local');
    expect($config['visibility'] ?? null)->toBe('private');
    expect($config['root'])->not->toContain('/public');
});
