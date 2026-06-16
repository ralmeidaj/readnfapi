<?php

namespace App\Contracts;

interface LlmServiceInterface
{
    public function extractFromPdf(string $pdfContent, string $prompt): array;
    public function extractFromImage(string $imageContent, string $mimeType, string $prompt): array;
    public function extractFromText(string $text, string $prompt): array;
}
