<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ExtratorException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ExtrairNotaFiscalRequest;
use App\Http\Resources\NotaFiscalResource;
use App\Services\NotaFiscalExtratorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use OpenApi\Attributes as OA;

class NotaFiscalController extends Controller
{
    public function __construct(private NotaFiscalExtratorService $extrator) {}

    #[OA\Post(
        path: '/api/v1/notas-fiscais/extrair',
        summary: 'Extrai dados de uma Nota Fiscal por PDF ou URL',
        tags: ['Notas Fiscais'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    properties: [
                        new OA\Property(
                            property: 'pdf',
                            type: 'string',
                            format: 'binary',
                            description: 'Arquivo PDF da Nota Fiscal (max. 10MB). Obrigatorio quando url nao for enviada.',
                            nullable: true
                        ),
                        new OA\Property(
                            property: 'url',
                            type: 'string',
                            format: 'uri',
                            description: 'URL publica da Nota Fiscal. Quando enviada, a pagina e renderizada internamente antes da extracao.',
                            nullable: true
                        ),
                        new OA\Property(
                            property: 'provider',
                            type: 'string',
                            enum: ['gemini', 'openai', 'mistral'],
                            description: 'Provedor LLM para PDFs (opcional, padrao: definido no .env)',
                            nullable: true
                        ),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Dados extraidos com sucesso',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'output',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'tomador_cnpj', type: 'string', example: '12.345.678/0001-90'),
                                new OA\Property(property: 'tomador_cpf', type: 'string', nullable: true, example: null),
                                new OA\Property(property: 'tomador_nome', type: 'string', example: 'Hospital Exemplo S.A.'),
                                new OA\Property(property: 'prestador_cnpj', type: 'string', nullable: true, example: null),
                                new OA\Property(property: 'prestador_cpf', type: 'string', example: '123.456.789-00'),
                                new OA\Property(property: 'prestador_nome', type: 'string', example: 'Dr. Joao Silva'),
                                new OA\Property(property: 'prestador_endereco', type: 'string', example: 'Rua das Flores, 123 - Aracaju/SE'),
                                new OA\Property(property: 'numero_nota_fiscal', type: 'string', example: '000123'),
                                new OA\Property(property: 'valor_total_nota_fiscal', type: 'number', format: 'float', example: 15000.00),
                                new OA\Property(property: 'data_emissao', type: 'string', example: '28/05/2026'),
                                new OA\Property(property: 'descricao', type: 'string', example: 'Servicos medicos prestados...'),
                            ]
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Nao autenticado'),
            new OA\Response(response: 422, description: 'Arquivo/URL invalido ou campos nao extraidos'),
            new OA\Response(response: 502, description: 'Falha na comunicacao com o servico externo'),
            new OA\Response(response: 504, description: 'Timeout no servico externo'),
        ]
    )]
    public function extrair(ExtrairNotaFiscalRequest $request): JsonResponse|NotaFiscalResource
    {
        try {
            $provider = $request->input('provider');

            $dados = $request->hasFile('pdf')
                ? $this->extrator->extrair($request->file('pdf'), $provider)
                : $this->extrator->extrairDeUrl($request->input('url'), $provider);

            Log::info('[NotaFiscalController] Resposta devolvida pela API', [
                'input_type' => $request->hasFile('pdf') ? 'pdf' : 'url',
                'url' => $request->input('url'),
                'provider' => $provider,
                'output' => $dados,
            ]);

            return new NotaFiscalResource($dados);
        } catch (ExtratorException $e) {
            Log::warning('[NotaFiscalController] Erro ao extrair nota fiscal', [
                'input_type' => $request->hasFile('pdf') ? 'pdf' : 'url',
                'url' => $request->input('url'),
                'provider' => $request->input('provider'),
                'status' => $e->getCode(),
                'message' => $e->getMessage(),
            ]);

            return response()->json(['message' => $e->getMessage()], $e->getCode());
        }
    }
}
