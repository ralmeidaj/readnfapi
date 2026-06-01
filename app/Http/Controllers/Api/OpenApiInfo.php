<?php

namespace App\Http\Controllers\Api;

use OpenApi\Attributes as OA;

#[OA\Info(
    title: 'Extract NF API',
    version: '1.0.0',
    description: 'API para extração de dados de Notas Fiscais de Serviço via Google Gemini 1.5 Flash',
)]
#[OA\SecurityScheme(
    securityScheme: 'bearerAuth',
    type: 'http',
    scheme: 'bearer',
    bearerFormat: 'JWT',
)]
#[OA\Server(url: 'http://localhost:8080')]
class OpenApiInfo {}
