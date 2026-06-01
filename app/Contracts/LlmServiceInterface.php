<?php

namespace App\Contracts;

interface LlmServiceInterface
{
    public function extractFromPdf(string $pdfContent, string $prompt): array;
}
