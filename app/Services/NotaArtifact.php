<?php

namespace App\Services;

class NotaArtifact
{
    public const PDF = 'pdf';
    public const IMAGE = 'image';
    public const TEXT = 'text';

    public function __construct(
        public readonly string $type,
        public readonly string $content,
        public readonly ?string $sourceUrl = null,
        public readonly ?string $contentType = null,
        public readonly array $metadata = [],
    ) {}
}
