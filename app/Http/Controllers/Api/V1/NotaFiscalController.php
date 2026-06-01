<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ExtratorException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ExtrairNotaFiscalRequest;
use App\Http\Resources\NotaFiscalResource;
use App\Services\NotaFiscalExtratorService;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class NotaFiscalController extends Controller
{
    public function __construct(private NotaFiscalExtratorService $extrator) {}

    #[OA\Post(
        path: '/api/v1/notas-fiscais/extrair',
        summary: 'Extrai dados de uma Nota Fiscal em PDF',
        tags: ['Notas Fiscais'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['pdf'],
                    properties: [
                        new OA\Property(
                            property: 'pdf',
                            type: 'string',
                            format: 'binary',
                            description: 'Arquivo PDF da Nota Fiscal (máx. 10MB)'
                        ),
                        new OA\Property(
                            property: 'provider',
                            type: 'string',
                            enum: ['gemini', 'openai', 'mistral'],
                            description: 'Provedor LLM (opcional, padrão: definido no .env)',
                            nullable: true
                        ),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Dados extraídos com sucesso',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'output',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'tomador_cnpj',            type: 'string',  example: '12.345.678/0001-90'),
                                new OA\Property(property: 'tomador_nome',            type: 'string',  example: 'Hospital Exemplo S.A.'),
                                new OA\Property(property: 'prestador_cnpj',          type: 'string',  example: '98.765.432/0001-10'),
                                new OA\Property(property: 'prestador_nome',          type: 'string',  example: 'Dr. João Silva ME'),
                                new OA\Property(property: 'prestador_endereco',      type: 'string',  example: 'Rua das Flores, 123 - Aracaju/SE'),
                                new OA\Property(property: 'numero_nota_fiscal',      type: 'string',  example: '000123'),
                                new OA\Property(property: 'valor_total_nota_fiscal', type: 'number',  format: 'float', example: 15000.00),
                                new OA\Property(property: 'data_emissao',            type: 'string',  example: '28/05/2026'),
                                new OA\Property(property: 'descricao',               type: 'string',  example: 'Serviços médicos prestados...'),
                            ]
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Não autenticado'),
            new OA\Response(response: 422, description: 'Arquivo inválido ou campos não extraídos'),
            new OA\Response(response: 502, description: 'Falha na comunicação com o serviço de LLM'),
            new OA\Response(response: 504, description: 'Timeout no serviço de LLM'),
        ]
    )]
    public function extrair(ExtrairNotaFiscalRequest $request): JsonResponse|NotaFiscalResource
    {
        try {
            $dados = $this->extrator->extrair($request->file('pdf'), $request->input('provider'));
            return new NotaFiscalResource($dados);
        } catch (ExtratorException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getCode());
        }
    }
}
