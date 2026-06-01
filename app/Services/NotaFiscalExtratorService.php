<?php

namespace App\Services;

use App\Contracts\LlmServiceInterface;
use App\Exceptions\ExtratorException;
use App\Providers\AppServiceProvider;
use Illuminate\Http\UploadedFile;

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
Você é um extrator de dados de Notas Fiscais de Serviço (NFS-e) brasileiras.
Extraia APENAS os campos abaixo do documento e retorne um JSON válido,
sem texto extra, sem markdown, somente o JSON.

Campos obrigatórios:
- tomador_cnpj (string, com pontuação — preencher se o tomador tiver CNPJ, senão null)
- tomador_cpf (string, com pontuação — preencher se o tomador tiver CPF, senão null)
- tomador_nome (string)
- prestador_cnpj (string, com pontuação — preencher se o prestador tiver CNPJ, senão null)
- prestador_cpf (string, com pontuação — preencher se o prestador tiver CPF, senão null)
- prestador_nome (string)
- prestador_endereco (string)
- numero_nota_fiscal (string)
- valor_total_nota_fiscal (number, sem R$, sem pontos de milhar, ponto decimal)
- data_emissao (string, formato DD/MM/YYYY)
- descricao (string, descrição dos serviços prestados)

Regras:
- tomador_cnpj e tomador_cpf são mutuamente exclusivos: preencha um e deixe o outro null
- prestador_cnpj e prestador_cpf são mutuamente exclusivos: preencha um e deixe o outro null
- Se um campo não for encontrado, retorne null para ele
PROMPT;

    public function __construct(private LlmServiceInterface $llm) {}

    public function extrair(UploadedFile $arquivo, ?string $provider = null): array
    {
        $pdfContent = file_get_contents($arquivo->getRealPath());

        $llm  = $provider ? AppServiceProvider::resolveProvider($provider) : $this->llm;
        $dados = $llm->extractFromPdf($pdfContent, self::PROMPT);

        $this->validarCampos($dados);

        if (isset($dados['valor_total_nota_fiscal']) && $dados['valor_total_nota_fiscal'] !== null) {
            $dados['valor_total_nota_fiscal'] = (float) $dados['valor_total_nota_fiscal'];
        }

        return $dados;
    }

    private function validarCampos(array $dados): void
    {
        $ausentes = array_filter(
            self::CAMPOS_OBRIGATORIOS,
            fn($campo) => ! array_key_exists($campo, $dados)
        );

        if (! empty($ausentes)) {
            throw new ExtratorException(
                'Campos não encontrados na resposta: ' . implode(', ', $ausentes),
                422
            );
        }
    }
}
