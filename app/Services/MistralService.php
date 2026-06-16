<?php

namespace App\Services;

use App\Contracts\LlmServiceInterface;
use App\Exceptions\ExtratorException;
use Illuminate\Support\Facades\Http;
use Smalot\PdfParser\Parser;

class MistralService implements LlmServiceInterface
{
    private string $apiKey;
    private string $model;
    private string $visionModel;
    private string $baseUrl = 'https://api.mistral.ai/v1/chat/completions';

    public function __construct()
    {
        $this->apiKey      = config('services.mistral.key');
        $this->model       = config('services.mistral.model', 'mistral-small-latest');
        $this->visionModel = config('services.mistral.vision_model', 'pixtral-12b-2409');
    }

    public function extractFromPdf(string $pdfContent, string $prompt): array
    {
        $text = $this->extractText($pdfContent);

        if (strlen(trim($text)) < 50) {
            return $this->extractPdfPageAsImage($pdfContent, $prompt);
        }

        return $this->extractFromText($text, $prompt);
    }

    public function extractFromImage(string $imageContent, string $mimeType, string $prompt): array
    {
        $base64 = base64_encode($imageContent);

        $response = Http::withToken($this->apiKey)
            ->timeout(60)
            ->post($this->baseUrl, [
                'model'    => $this->visionModel,
                'messages' => [[
                    'role'    => 'user',
                    'content' => [
                        [
                            'type'      => 'image_url',
                            'image_url' => ['url' => "data:{$mimeType};base64,{$base64}"],
                        ],
                        ['type' => 'text', 'text' => $prompt],
                    ],
                ]],
                'response_format' => ['type' => 'json_object'],
            ]);

        if (! $response->successful()) {
            \Log::error('Mistral image error', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            throw new ExtratorException('Falha ao processar o documento. Tente novamente.', 502);
        }

        return $this->parseResponse(data_get($response->json(), 'choices.0.message.content'));
    }

    public function extractFromText(string $text, string $prompt): array
    {
        $response = Http::withToken($this->apiKey)
            ->timeout(60)
            ->post($this->baseUrl, [
                'model'           => $this->model,
                'messages'        => [[
                    'role'    => 'user',
                    'content' => $prompt . "\n\n---\nConteúdo do documento:\n" . $text,
                ]],
                'response_format' => ['type' => 'json_object'],
            ]);

        if (! $response->successful()) {
            \Log::error('Mistral API error', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            throw new ExtratorException('Falha ao processar o documento. Tente novamente.', 502);
        }

        return $this->parseResponse(data_get($response->json(), 'choices.0.message.content'));
    }

    private function extractPdfPageAsImage(string $pdfContent, string $prompt): array
    {
        $tmpPdf  = tempnam(sys_get_temp_dir(), 'nf_') . '.pdf';
        $tmpImg  = sys_get_temp_dir() . '/nf_page';
        $imgFile = $tmpImg . '-1.png';

        try {
            file_put_contents($tmpPdf, $pdfContent);

            $cmd    = "pdftoppm -r 200 -png -f 1 -l 1 " . escapeshellarg($tmpPdf) . " " . escapeshellarg($tmpImg) . " 2>&1";
            $output = shell_exec($cmd);

            if (! file_exists($imgFile)) {
                \Log::error('pdftoppm failed (Mistral)', ['output' => $output]);
                throw new ExtratorException('Não foi possível processar este PDF.', 502);
            }

            $base64 = base64_encode(file_get_contents($imgFile));

            $response = Http::withToken($this->apiKey)
                ->timeout(60)
                ->post($this->baseUrl, [
                    'model'    => $this->visionModel,
                    'messages' => [[
                        'role'    => 'user',
                        'content' => [
                            [
                                'type'      => 'image_url',
                                'image_url' => ['url' => "data:image/png;base64,{$base64}"],
                            ],
                            ['type' => 'text', 'text' => $prompt],
                        ],
                    ]],
                    'response_format' => ['type' => 'json_object'],
                ]);

            if (! $response->successful()) {
                \Log::error('Mistral vision error', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                throw new ExtratorException('Falha ao processar o documento. Tente novamente.', 502);
            }

            return $this->parseResponse(data_get($response->json(), 'choices.0.message.content'));

        } catch (ExtratorException $e) {
            throw $e;
        } catch (\Exception $e) {
            \Log::error('Mistral image error', ['message' => $e->getMessage()]);
            throw new ExtratorException('Falha ao processar o documento. Tente novamente.', 502);
        } finally {
            @unlink($tmpPdf);
            @unlink($imgFile);
        }
    }

    private function extractText(string $pdfContent): string
    {
        try {
            $parser = new Parser();
            $pdf    = $parser->parseContent($pdfContent);
            $text   = $pdf->getText();

            \Log::info('PDF text extracted (Mistral)', [
                'length'  => strlen($text),
                'preview' => substr(trim($text), 0, 200),
            ]);

            return $text ?? '';
        } catch (\Exception $e) {
            \Log::warning('PDF text extraction failed (Mistral)', ['message' => $e->getMessage()]);
            return '';
        }
    }

    private function parseResponse(?string $content): array
    {
        if (blank($content)) {
            throw new ExtratorException('Falha ao processar o documento. Tente novamente.', 502);
        }

        $content = preg_replace('/^```(?:json)?\s*/i', '', trim($content));
        $content = preg_replace('/\s*```$/', '', $content);

        $decoded = json_decode(trim($content), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            \Log::error('Mistral JSON parse error', ['content' => $content]);
            throw new ExtratorException('Falha ao processar o documento. Tente novamente.', 502);
        }

        return $decoded;
    }
}
