<?php

namespace App\Services;

use App\Contracts\LlmServiceInterface;
use App\Exceptions\ExtratorException;
use OpenAI;
use Smalot\PdfParser\Parser;

class OpenAiService implements LlmServiceInterface
{
    private string $apiKey;
    private string $model;

    public function __construct()
    {
        $this->apiKey = config('services.openai.key');
        $this->model  = config('services.openai.model', 'gpt-4o-mini');
    }

    public function extractFromPdf(string $pdfContent, string $prompt): array
    {
        $text = $this->extractText($pdfContent);

        // Se o texto extraído for muito curto, usar visão (imagem)
        if (strlen(trim($text)) < 50) {
            return $this->extractFromImage($pdfContent, $prompt);
        }

        try {
            $client = OpenAI::client($this->apiKey);

            $response = $client->chat()->create([
                'model'    => $this->model,
                'messages' => [
                    [
                        'role'    => 'user',
                        'content' => $prompt . "\n\n---\nConteúdo do documento:\n" . $text,
                    ],
                ],
                'response_format' => ['type' => 'json_object'],
            ]);
        } catch (\Exception $e) {
            \Log::error('OpenAI API error', ['message' => $e->getMessage()]);
            throw new ExtratorException('Falha ao processar o documento. Tente novamente.', 502);
        }

        return $this->parseResponse($response->choices[0]->message->content ?? null);
    }

    private function extractFromImage(string $pdfContent, string $prompt): array
    {
        $tmpPdf = tempnam(sys_get_temp_dir(), 'nf_') . '.pdf';
        $tmpImg = sys_get_temp_dir() . '/nf_page';

        try {
            file_put_contents($tmpPdf, $pdfContent);

            // Converte primeira página do PDF em PNG via poppler-utils
            $cmd    = "pdftoppm -r 200 -png -f 1 -l 1 " . escapeshellarg($tmpPdf) . " " . escapeshellarg($tmpImg) . " 2>&1";
            $output = shell_exec($cmd);
            $imgFile = $tmpImg . '-1.png';

            if (! file_exists($imgFile)) {
                \Log::error('pdftoppm failed', ['output' => $output]);
                throw new ExtratorException('Não foi possível processar este PDF. Tente novamente.', 502);
            }

            $base64 = base64_encode(file_get_contents($imgFile));

            $client   = OpenAI::client($this->apiKey);
            $response = $client->chat()->create([
                'model'    => $this->model,
                'messages' => [
                    [
                        'role'    => 'user',
                        'content' => [
                            [
                                'type'      => 'image_url',
                                'image_url' => ['url' => "data:image/png;base64,{$base64}"],
                            ],
                            ['type' => 'text', 'text' => $prompt],
                        ],
                    ],
                ],
                'response_format' => ['type' => 'json_object'],
            ]);

            return $this->parseResponse($response->choices[0]->message->content ?? null);

        } catch (ExtratorException $e) {
            throw $e;
        } catch (\Exception $e) {
            \Log::error('OpenAI vision error', ['message' => $e->getMessage()]);
            throw new ExtratorException('Falha ao processar o documento. Tente novamente.', 502);
        } finally {
            @unlink($tmpPdf);
            @unlink($tmpImg . '-1.png');
        }
    }

    private function extractText(string $pdfContent): string
    {
        try {
            $parser = new Parser();
            $pdf    = $parser->parseContent($pdfContent);
            $text   = $pdf->getText();

            \Log::info('PDF text extracted', [
                'length'  => strlen($text),
                'preview' => substr(trim($text), 0, 200),
            ]);

            return $text ?? '';
        } catch (\Exception $e) {
            \Log::warning('PDF text extraction failed', ['message' => $e->getMessage()]);
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
            \Log::error('OpenAI JSON parse error', ['content' => $content]);
            throw new ExtratorException('Falha ao processar o documento. Tente novamente.', 502);
        }

        return $decoded;
    }
}
