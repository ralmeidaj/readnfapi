<?php

namespace App\Services;

use App\Exceptions\ExtratorException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Spatie\Browsershot\Browsershot;

class NotaArtifactResolver
{
    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/126.0 Safari/537.36';

    public function resolve(string $url): NotaArtifact
    {
        Log::info('[NotaArtifactResolver] Iniciando resolucao da URL', ['url' => $url]);

        $response = $this->fetch($url);
        $cookies = $this->extractCookies($response);

        if ($artifact = $this->artifactFromResponse($response, $url)) {
            return $artifact;
        }

        $html = $response->body();

        if (! $this->looksLikeHtml($response, $html)) {
            throw new ExtratorException('A URL informada nao retornou uma nota fiscal valida.', 422);
        }

        if ($artifact = $this->resolveLinkedArtifact($html, $url, $cookies)) {
            return $artifact;
        }

        $text = $this->htmlToText($html);

        if ($this->textLooksLikeInvoice($text)) {
            Log::info('[NotaArtifactResolver] HTML inicial contem texto util da nota', [
                'url' => $url,
                'length' => strlen($text),
            ]);

            return new NotaArtifact(NotaArtifact::TEXT, $text, $url, 'text/plain', ['stage' => 'initial_html_text']);
        }

        return $this->resolveWithBrowser($url);
    }

    public function imageToPdf(NotaArtifact $artifact): string
    {
        if ($artifact->type !== NotaArtifact::IMAGE) {
            throw new \InvalidArgumentException('Apenas artefatos de imagem podem ser convertidos para PDF.');
        }

        $mime = $artifact->contentType ?: 'image/png';
        $base64 = base64_encode($artifact->content);
        $html = <<<HTML
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 12mm; }
        body { margin: 0; background: #fff; }
        img { display: block; width: 100%; height: auto; }
    </style>
</head>
<body>
    <img src="data:{$mime};base64,{$base64}" alt="Nota Fiscal">
</body>
</html>
HTML;

        try {
            return Browsershot::html($html)
                ->noSandbox()
                ->showBackground()
                ->format('A4')
                ->timeout((int) config('services.browser.timeout', 60))
                ->pdf();
        } catch (\Throwable $e) {
            Log::error('[NotaArtifactResolver] Falha ao converter imagem em PDF', [
                'source_url' => $artifact->sourceUrl,
                'message' => $e->getMessage(),
            ]);

            throw new ExtratorException('Nao foi possivel converter a imagem da nota fiscal em PDF.', 422);
        }
    }

    private function resolveLinkedArtifact(string $html, string $baseUrl, array $cookies): ?NotaArtifact
    {
        $candidates = $this->extractCandidates($html, $baseUrl);

        Log::info('[NotaArtifactResolver] Candidatos encontrados no HTML', [
            'base_url' => $baseUrl,
            'count' => count($candidates),
            'top' => array_slice(array_column($candidates, 'url'), 0, 5),
        ]);

        foreach ($candidates as $candidate) {
            try {
                $response = $this->fetch($candidate['url'], $baseUrl, $cookies);
            } catch (\Throwable $e) {
                Log::warning('[NotaArtifactResolver] Falha ao baixar candidato', [
                    'url' => $candidate['url'],
                    'message' => $e->getMessage(),
                ]);

                continue;
            }

            if ($artifact = $this->artifactFromResponse($response, $candidate['url'])) {
                Log::info('[NotaArtifactResolver] Artefato obtido por candidato HTML', [
                    'type' => $artifact->type,
                    'source_url' => $artifact->sourceUrl,
                    'content_type' => $artifact->contentType,
                    'score' => $candidate['score'],
                ]);

                return $artifact;
            }
        }

        return null;
    }

    private function resolveWithBrowser(string $url): NotaArtifact
    {
        Log::info('[NotaArtifactResolver] Entrando no fallback com Chromium', ['url' => $url]);

        try {
            $browser = $this->browser($url);
            $html = $browser->bodyHtml();
        } catch (\Throwable $e) {
            Log::warning('[NotaArtifactResolver] Falha ao ler HTML com Chromium', [
                'url' => $url,
                'message' => $e->getMessage(),
            ]);

            $html = '';
        }

        if ($html !== '') {
            if ($artifact = $this->resolveLinkedArtifact($html, $url, [])) {
                return $artifact;
            }

            $text = $this->htmlToText($html);

            if ($this->textLooksLikeInvoice($text)) {
                Log::info('[NotaArtifactResolver] Chromium encontrou texto util da nota', [
                    'url' => $url,
                    'length' => strlen($text),
                ]);

                return new NotaArtifact(NotaArtifact::TEXT, $text, $url, 'text/plain', ['stage' => 'browser_text']);
            }
        }

        try {
            $pdf = $this->browser($url)
                ->showBackground()
                ->format('A4')
                ->pdf();
        } catch (\Throwable $e) {
            Log::error('[NotaArtifactResolver] Falha ao gerar PDF com Chromium', [
                'url' => $url,
                'message' => $e->getMessage(),
            ]);

            throw new ExtratorException('Nao foi possivel obter a nota fiscal pela URL informada.', 422);
        }

        if (! $this->looksLikePdf($pdf)) {
            throw new ExtratorException('A URL informada nao gerou um PDF valido da nota fiscal.', 422);
        }

        Log::info('[NotaArtifactResolver] PDF gerado com Chromium', [
            'url' => $url,
            'length' => strlen($pdf),
        ]);

        return new NotaArtifact(NotaArtifact::PDF, $pdf, $url, 'application/pdf', ['stage' => 'browser_pdf']);
    }

    private function artifactFromResponse(Response $response, string $url): ?NotaArtifact
    {
        $body = $response->body();
        $contentType = strtolower((string) $response->header('Content-Type'));

        if (! $response->successful() || $body === '') {
            return null;
        }

        if (str_contains($contentType, 'application/pdf') || $this->looksLikePdf($body)) {
            if (strlen($body) < (int) config('services.artifact.min_pdf_bytes', 1500)) {
                return null;
            }

            Log::info('[NotaArtifactResolver] PDF valido encontrado', [
                'url' => $url,
                'content_type' => $contentType,
                'length' => strlen($body),
            ]);

            return new NotaArtifact(NotaArtifact::PDF, $body, $url, 'application/pdf', ['stage' => 'direct_pdf']);
        }

        if (str_starts_with($contentType, 'image/') || $this->looksLikeImage($body)) {
            $imageInfo = @getimagesizefromstring($body);

            if (! $imageInfo) {
                return null;
            }

            [$width, $height] = $imageInfo;
            $minBytes = (int) config('services.artifact.min_image_bytes', 3000);
            $minSide = (int) config('services.artifact.min_image_side', 350);

            if (strlen($body) < $minBytes || max($width, $height) < $minSide) {
                Log::info('[NotaArtifactResolver] Imagem descartada por ser pequena', [
                    'url' => $url,
                    'length' => strlen($body),
                    'width' => $width,
                    'height' => $height,
                ]);

                return null;
            }

            Log::info('[NotaArtifactResolver] Imagem valida encontrada', [
                'url' => $url,
                'content_type' => $contentType,
                'length' => strlen($body),
                'width' => $width,
                'height' => $height,
            ]);

            return new NotaArtifact(
                NotaArtifact::IMAGE,
                $body,
                $url,
                $imageInfo['mime'] ?? $contentType,
                ['stage' => 'direct_image', 'width' => $width, 'height' => $height]
            );
        }

        return null;
    }

    private function fetch(string $url, ?string $referer = null, array $cookies = []): Response
    {
        $headers = [
            'User-Agent' => self::USER_AGENT,
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,application/pdf,image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
            'Accept-Language' => 'pt-BR,pt;q=0.9,en-US;q=0.8,en;q=0.7',
        ];

        if ($referer) {
            $headers['Referer'] = $referer;
        }

        if ($cookies !== []) {
            $headers['Cookie'] = $this->cookieHeader($cookies);
        }

        return Http::withHeaders($headers)
            ->withOptions(['verify' => false, 'allow_redirects' => true])
            ->timeout((int) config('services.artifact.http_timeout', 30))
            ->get($url);
    }

    private function extractCandidates(string $html, string $baseUrl): array
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);
        $nodes = $xpath->query('//*[@href or @src or @data]');
        $candidates = [];

        foreach ($nodes as $node) {
            foreach (['href', 'src', 'data'] as $attribute) {
                if (! $node->hasAttribute($attribute)) {
                    continue;
                }

                $rawUrl = trim($node->getAttribute($attribute));

                if ($rawUrl === '' || str_starts_with($rawUrl, '#') || str_starts_with($rawUrl, 'javascript:')) {
                    continue;
                }

                $absoluteUrl = $this->absoluteUrl($rawUrl, $baseUrl);

                if (! $absoluteUrl || ! preg_match('/^https?:\/\//i', $absoluteUrl)) {
                    continue;
                }

                $score = $this->candidateScore($absoluteUrl, strtolower($node->nodeName), $attribute);

                if ($score <= 0) {
                    continue;
                }

                $candidates[$absoluteUrl] = [
                    'url' => $absoluteUrl,
                    'score' => max($score, $candidates[$absoluteUrl]['score'] ?? 0),
                ];
            }
        }

        usort($candidates, fn($a, $b) => $b['score'] <=> $a['score']);

        return array_values($candidates);
    }

    private function candidateScore(string $url, string $tag, string $attribute): int
    {
        $lowerUrl = strtolower(urldecode($url));
        $score = 0;

        if (str_contains($lowerUrl, '.pdf') || str_contains($lowerUrl, 'pdf')) {
            $score += 80;
        }

        if (preg_match('/\.(png|jpe?g|webp)(\?|$)/', $lowerUrl)) {
            $score += 60;
        }

        foreach (['nota', 'nfse', 'nfs-e', 'danfe', 'print', 'imprim', 'visualiz', 'documento', 'download'] as $term) {
            if (str_contains($lowerUrl, $term)) {
                $score += 12;
            }
        }

        if (in_array($tag, ['iframe', 'embed', 'object'], true)) {
            $score += 20;
        }

        if ($tag === 'img') {
            $score += 15;
        }

        if ($attribute === 'data') {
            $score += 10;
        }

        foreach (['logo', 'captcha', 'favicon', 'spinner', 'loading', 'ajax-loader', 'blank'] as $negative) {
            if (str_contains($lowerUrl, $negative)) {
                $score -= 80;
            }
        }

        return $score;
    }

    private function htmlToText(string $html): string
    {
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html);
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html);

        return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html))));
    }

    private function textLooksLikeInvoice(string $text): bool
    {
        $normalized = mb_strtolower($text);

        if (str_contains($normalized, 'aguarde') && str_contains($normalized, 'carregando')) {
            return false;
        }

        $signals = [
            'nota fiscal',
            'nfse',
            'nfs-e',
            'prestador',
            'tomador',
            'valor',
            'emissao',
            'emissão',
            'cnpj',
            'cpf',
            'servico',
            'serviço',
        ];

        $found = array_filter($signals, fn($signal) => str_contains($normalized, $signal));

        return strlen($text) >= (int) config('services.artifact.min_text_chars', 500) && count($found) >= 3;
    }

    private function looksLikeHtml(Response $response, string $body): bool
    {
        $contentType = strtolower((string) $response->header('Content-Type'));

        return str_contains($contentType, 'html')
            || str_contains(ltrim($body), '<!doctype html')
            || str_contains(ltrim($body), '<html');
    }

    private function looksLikePdf(string $body): bool
    {
        return str_starts_with(ltrim($body), '%PDF');
    }

    private function looksLikeImage(string $body): bool
    {
        return @getimagesizefromstring($body) !== false;
    }

    private function absoluteUrl(string $url, string $baseUrl): ?string
    {
        if (preg_match('/^https?:\/\//i', $url)) {
            return $url;
        }

        $base = parse_url($baseUrl);

        if (! isset($base['scheme'], $base['host'])) {
            return null;
        }

        if (str_starts_with($url, '//')) {
            return $base['scheme'] . ':' . $url;
        }

        $root = $base['scheme'] . '://' . $base['host'] . (isset($base['port']) ? ':' . $base['port'] : '');

        if (str_starts_with($url, '/')) {
            return $root . $url;
        }

        $path = $base['path'] ?? '/';
        $directory = preg_replace('#/[^/]*$#', '/', $path);

        return $root . $directory . $url;
    }

    private function extractCookies(Response $response): array
    {
        $cookies = [];
        $headers = $response->headers();
        $setCookieHeaders = $headers['Set-Cookie']
            ?? $headers['set-cookie']
            ?? $headers['Set-cookie']
            ?? [];

        if (is_string($setCookieHeaders)) {
            $setCookieHeaders = [$setCookieHeaders];
        }

        foreach ($setCookieHeaders as $cookie) {
            $pair = explode(';', $cookie, 2)[0] ?? '';

            if (str_contains($pair, '=')) {
                [$name, $value] = explode('=', $pair, 2);
                $cookies[trim($name)] = trim($value);
            }
        }

        return $cookies;
    }

    private function cookieHeader(array $cookies): string
    {
        return implode('; ', array_map(
            fn($name, $value) => "{$name}={$value}",
            array_keys($cookies),
            $cookies
        ));
    }

    private function browser(string $url): Browsershot
    {
        return Browsershot::url($url)
            ->noSandbox()
            ->windowSize(1280, 1600)
            ->waitUntilNetworkIdle()
            ->setDelay((int) config('services.browser.render_delay_ms', 5000))
            ->timeout((int) config('services.browser.timeout', 60));
    }
}
