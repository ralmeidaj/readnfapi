<?php

namespace App\Services;

use App\Contracts\LlmServiceInterface;
use App\Exceptions\ExtratorException;
use App\Providers\AppServiceProvider;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

class NotaFiscalExtratorService
{
    private const CAMPOS_OBRIGATORIOS = [
        'tomador_cnpj',
        'tomador_cpf',
        'tomador_nome',
        'prestador_cnpj',
        'prestador_cpf',
        'prestador_nome',
        'prestador_endereco',
        'numero_nota_fiscal',
        'valor_total_nota_fiscal',
        'data_emissao',
        'descricao',
    ];

    private const PROMPT = <<<'PROMPT'
Voce e um extrator de dados de Notas Fiscais de Servico (NFS-e) brasileiras.
Extraia APENAS os campos abaixo do documento e retorne um JSON valido,
sem texto extra, sem markdown, somente o JSON.

Campos obrigatorios:
- tomador_cnpj (string, com pontuacao, preencher se o tomador tiver CNPJ, senao null)
- tomador_cpf (string, com pontuacao, preencher se o tomador tiver CPF, senao null)
- tomador_nome (string)
- prestador_cnpj (string, com pontuacao, preencher se o prestador tiver CNPJ, senao null)
- prestador_cpf (string, com pontuacao, preencher se o prestador tiver CPF, senao null)
- prestador_nome (string)
- prestador_endereco (string)
- numero_nota_fiscal (string)
- valor_total_nota_fiscal (number, sem R$, sem pontos de milhar, ponto decimal)
- data_emissao (string, formato DD/MM/YYYY)
- descricao (string, descricao dos servicos prestados)

Regras:
- tomador_cnpj e tomador_cpf sao mutuamente exclusivos: preencha um e deixe o outro null
- prestador_cnpj e prestador_cpf sao mutuamente exclusivos: preencha um e deixe o outro null
- Se um campo nao for encontrado, retorne null para ele
PROMPT;

    public function __construct(
        private LlmServiceInterface $llm,
        private NotaArtifactResolver $artifactResolver,
    ) {}

    public function extrair(UploadedFile $arquivo, ?string $provider = null): array
    {
        $pdfContent = file_get_contents($arquivo->getRealPath());

        $llm = $provider ? AppServiceProvider::resolveProvider($provider) : $this->llm;
        $dados = $llm->extractFromPdf($pdfContent, self::PROMPT);

        $this->validarCampos($dados);

        return $this->normalizarDados($dados);
    }

    public function extrairDeUrl(string $url, ?string $provider = null): array
    {
        Log::info('[extrairDeUrl] Iniciando aquisicao da nota fiscal', ['url' => $url, 'provider' => $provider]);

        $llm = $provider ? AppServiceProvider::resolveProvider($provider) : $this->llm;
        $artifact = $this->artifactResolver->resolve($url);

        Log::info('[extrairDeUrl] Artefato da nota fiscal obtido', [
            'type' => $artifact->type,
            'source_url' => $artifact->sourceUrl,
            'content_type' => $artifact->contentType,
            'length' => strlen($artifact->content),
            'metadata' => $artifact->metadata,
        ]);

        $dados = match ($artifact->type) {
            NotaArtifact::TEXT => $llm->extractFromText($artifact->content, self::PROMPT),
            NotaArtifact::IMAGE => $llm->extractFromImage(
                $artifact->content,
                $artifact->contentType ?: 'image/png',
                self::PROMPT
            ),
            default => $llm->extractFromPdf($artifact->content, self::PROMPT),
        };

        $this->validarCampos($dados);
        $this->validarResultadoUtil($dados);

        return $this->normalizarDados($dados);
    }

    private function validarCampos(array $dados): void
    {
        $ausentes = array_filter(
            self::CAMPOS_OBRIGATORIOS,
            fn($campo) => ! array_key_exists($campo, $dados)
        );

        if (! empty($ausentes)) {
            throw new ExtratorException(
                'Campos nao encontrados na resposta: ' . implode(', ', $ausentes),
                422
            );
        }
    }

    private function normalizarDados(array $dados): array
    {
        if (isset($dados['valor_total_nota_fiscal']) && $dados['valor_total_nota_fiscal'] !== null) {
            $dados['valor_total_nota_fiscal'] = (float) $dados['valor_total_nota_fiscal'];
        }

        return $dados;
    }

    private function validarResultadoUtil(array $dados): void
    {
        $camposChave = [
            'tomador_nome',
            'prestador_nome',
            'numero_nota_fiscal',
            'valor_total_nota_fiscal',
            'data_emissao',
            'descricao',
        ];

        foreach ($camposChave as $campo) {
            if (! blank($dados[$campo] ?? null)) {
                return;
            }
        }

        throw new ExtratorException('Nao foi possivel extrair dados uteis da nota fiscal.', 422);
    }
}
