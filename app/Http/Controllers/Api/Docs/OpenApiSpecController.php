<?php

namespace App\Http\Controllers\Api\Docs;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

class OpenApiSpecController extends Controller
{
    public function __invoke(): Response
    {
        $path = base_path('docs/openapi.yaml');

        abort_unless(is_file($path), 404);

        return response(file_get_contents($path), Response::HTTP_OK, [
            'Content-Type' => 'application/yaml; charset=UTF-8',
        ]);
    }
}
