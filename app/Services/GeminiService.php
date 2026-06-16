<?php

namespace App\Services;

use App\Contracts\LlmServiceInterface;
use App\Exceptions\ExtratorException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Client\ConnectionException;

class GeminiService implements LlmServiceInterface
{
    private string $apiKey;
    private string $model;
    private string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta/models';

    public function __construct()
    {
        $this->apiKey = config('services.gemini.key');
        $this->model  = config('services.gemini.model', 'gemini-2.0-flash');
    }

    public function extractFromPdf(string $pdfContent, string $prompt): array
    {
        $payload = [
            'contents' => [[
                'parts' => [
                    [
                        'inline_data' => [
                            'mime_type' => 'application/pdf',
                            'data'      => base64_encode($pdfContent),
                        ],
                    ],
                    ['text' => $prompt],
                ],
            ]],
            'generationConfig' => ['responseMimeType' => 'application/json'],
        ];

        return $this->callApi($payload);
    }

    public function extractFromImage(string $imageContent, string $mimeType, string $prompt): array
    {
        $payload = [
            'contents' => [[
                'parts' => [
                    [
                        'inline_data' => [
                            'mime_type' => $mimeType,
                            'data'      => base64_encode($imageContent),
                        ],
                    ],
                    ['text' => $prompt],
                ],
            ]],
            'generationConfig' => ['responseMimeType' => 'application/json'],
        ];

        return $this->callApi($payload);
    }

    public function extractFromText(string $text, string $prompt): array
    {
        $payload = [
            'contents' => [[
                'parts' => [
                    ['text' => $text],
                    ['text' => $prompt],
                ],
            ]],
            'generationConfig' => ['responseMimeType' => 'application/json'],
        ];

        return $this->callApi($payload);
    }

    private function callApi(array $payload): array
    {
        $url = "{$this->baseUrl}/{$this->model}:generateContent?key={$this->apiKey}";

        try {
            $response = Http::withOptions(['verify' => false])->timeout(60)->post($url, $payload);
        } catch (ConnectionException $e) {
            Log::error('[GeminiService] ConnectionException', ['message' => $e->getMessage(), 'url' => $url]);
            throw new ExtratorException('Serviço de extração indisponível.', 504);
        }

        if ($response->status() === 429) {
            Log::warning('Gemini 429', ['body' => $response->body()]);
            throw new ExtratorException('Limite de requisições atingido. Tente novamente em instantes.', 429);
        }

        if (! $response->successful()) {
            Log::error('Gemini API error', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            throw new ExtratorException('Falha ao processar o documento. Tente novamente.', 502);
        }

        $text = data_get($response->json(), 'candidates.0.content.parts.0.text');

        if (blank($text)) {
            Log::error('Gemini empty response', ['body' => $response->body()]);
            throw new ExtratorException('Falha ao processar o documento. Tente novamente.', 502);
        }

        $text = preg_replace('/^```(?:json)?\s*/i', '', trim($text));
        $text = preg_replace('/\s*```$/', '', $text);

        $decoded = json_decode(trim($text), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error('Gemini JSON parse error', ['text' => $text]);
            throw new ExtratorException('Falha ao processar o documento. Tente novamente.', 502);
        }

        return $decoded;
    }
}
