<?php

namespace App\Http\Controllers\Api;

use App\Enums\InputType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreAnalysisRequest;
use App\Http\Resources\AnalysisListResource;
use App\Http\Resources\AnalysisResource;
use App\Models\Analysis;
use App\Models\User;
use App\Services\AnalyzeProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AnalysisController extends Controller
{
    public function __construct(private AnalyzeProductService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $analyses = Analysis::query()
            ->with(['inputType', 'recommendationDecision'])
            ->where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(15);

        return AnalysisListResource::collection($analyses);
    }

    public function store(StoreAnalysisRequest $request): AnalysisResource
    {
        /** @var User $user */
        $user = $request->user();

        $analysis = match ($request->inputTypeEnum()) {
            InputType::Text => $this->service->analyzeText(
                user: $user,
                query: $request->validated('query'),
            ),
            InputType::Image => $this->service->analyzeImage(
                user: $user,
                imagePath: $this->storeUploadedImage($request),
            ),
        };

        $analysis->load(['similarProducts', 'inputType', 'recommendationDecision']);

        return AnalysisResource::make($analysis);
    }

    public function show(Request $request, Analysis $analysis): AnalysisResource
    {
        $this->ensureOwner($request, $analysis);

        $analysis->load(['similarProducts', 'inputType', 'recommendationDecision']);

        return AnalysisResource::make($analysis);
    }

    public function destroy(Request $request, Analysis $analysis): Response
    {
        $this->ensureOwner($request, $analysis);

        $imagePath = $analysis->image_path;

        DB::transaction(function () use ($analysis): void {
            $analysis->delete();
        });

        if ($imagePath !== null) {
            Storage::disk('analysis_images')->delete($imagePath);
        }

        return response()->noContent();
    }

    public function image(Request $request, Analysis $analysis): StreamedResponse|JsonResponse
    {
        $this->ensureOwner($request, $analysis);

        if ($analysis->image_path === null) {
            abort(404);
        }

        $disk = Storage::disk('analysis_images');

        if (! $disk->exists($analysis->image_path)) {
            abort(404);
        }

        $downloadName = basename($analysis->image_path);

        return $disk->download($analysis->image_path, $downloadName);
    }

    private function ensureOwner(Request $request, Analysis $analysis): void
    {
        if ($analysis->user_id !== $request->user()->id) {
            abort(404);
        }
    }

    private function storeUploadedImage(StoreAnalysisRequest $request): string
    {
        $file = $request->file('image');

        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $filename = Str::uuid7()->toString().'.'.$extension;

        return $file->storeAs('analyses', $filename, 'analysis_images');
    }
}
