# Plano: API de Extração de Notas Fiscais com LLM

## Contexto

O projeto `extract-nf` é uma aplicação Laravel 12 criada do zero. O objetivo é expor um endpoint REST que recebe um PDF de Nota Fiscal, envia o conteúdo para o **Google Gemini 1.5 Flash** (que atua como OCR + extrator inteligente), e retorna os dados estruturados em JSON. Não há bibliotecas tradicionais de OCR envolvidas — o LLM lê o PDF diretamente.

---

## Stack e Decisões Técnicas

| Decisão | Escolha | Motivo |
|---|---|---|
| LLM | **Google Gemini 1.5 Flash** | Suporte nativo a PDF, camada gratuita (1500 req/dia), muito barato (~$0.0002/NF) |
| HTTP para LLM | `Laravel Http` facade (Guzzle) | Sem lib extra; chamada direta à Gemini API REST |
| Auth | `php-open-source-saver/jwt-auth` (HS256) | Alinhado à stack definida |
| Docs | `darkaonline/l5-swagger` (OpenAPI 3.0) | Alinhado à stack definida |
| Infraestrutura | **Docker** (PHP 8.2-fpm + Nginx + Redis) | PHP não instalado localmente; ambiente isolado e reproduzível |
| Processamento | **Síncrono** (Fase 1) → Queue/Horizon (Fase 2) | PDFs de NF são geralmente pequenos; fila como evolução |

---

## Arquitetura do Fluxo

```
Cliente
  │  POST /api/v1/notas-fiscais/extrair
  │  Authorization: Bearer <JWT>
  │  Content-Type: multipart/form-data
  │  Body: pdf (file)
  ▼
NotaFiscalController
  │
  ├── ExtrairNotaFiscalRequest (valida: mime=pdf, max 10MB)
  │
  ▼
NotaFiscalExtratorService
  │  1. Lê o arquivo → base64
  │  2. Monta payload para Gemini API
  │
  ▼
GeminiService (wrapper HTTP)
  │  POST https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent
  │  ?key=GEMINI_API_KEY
  │  responseMimeType: application/json
  │
  ▼
  Resposta LLM (JSON)
  │
  ▼
NotaFiscalExtratorService
  │  3. Parse do JSON retornado
  │  4. Valida campos obrigatórios
  │
  ▼
NotaFiscalResource → JSON final ao cliente
```

---

## JSON de Saída

```json
{
  "output": {
    "tomador_cnpj": "12.345.678/0001-90",
    "tomador_nome": "Hospital Exemplo S.A.",
    "prestador_cnpj": "98.765.432/0001-10",
    "prestador_nome": "Dr. João Silva ME",
    "prestador_endereco": "Rua das Flores, 123 - Aracaju/SE",
    "numero_nota_fiscal": "000123",
    "valor_total_nota_fiscal": 15000.00,
    "data_emissao": "28/05/2026",
    "descricao": "Serviços médicos prestados..."
  }
}
```

---

## Estrutura de Diretórios

```
extract-nf/
├── docker/
│   ├── php/Dockerfile
│   └── nginx/default.conf
├── docker-compose.yml
├── app/
│   ├── Http/
│   │   ├── Controllers/Api/V1/
│   │   │   └── NotaFiscalController.php
│   │   ├── Requests/
│   │   │   └── ExtrairNotaFiscalRequest.php
│   │   └── Resources/
│   │       └── NotaFiscalResource.php
│   ├── Services/
│   │   ├── GeminiService.php               ← wrapper Gemini API
│   │   └── NotaFiscalExtratorService.php
│   └── Exceptions/
│       └── ExtratorException.php
├── routes/
│   └── api.php
├── config/
│   └── services.php                        ← GEMINI_API_KEY
└── tests/
    └── Feature/
        └── ExtrairNotaFiscalTest.php
```

---

## Arquivos Críticos

### `routes/api.php`
```php
Route::prefix('v1')->middleware('auth:api')->group(function () {
    Route::post('notas-fiscais/extrair', [NotaFiscalController::class, 'extrair']);
});
```

### `ExtrairNotaFiscalRequest`
- Valida: `pdf` obrigatório, mimes `pdf`, max `10240` KB
- Mensagens de erro em PT-BR

### `GeminiService`
```php
$url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent'
     . '?key=' . config('services.gemini.key');

Http::post($url, [
    'contents' => [[
        'parts' => [
            [
                'inline_data' => [
                    'mime_type' => 'application/pdf',
                    'data'      => base64_encode($pdfContent),
                ],
            ],
            ['text' => $prompt],
        ],
    ]],
    'generationConfig' => [
        'responseMimeType' => 'application/json',
    ],
]);
```

### Prompt de Extração
```
Você é um extrator de dados de Notas Fiscais de Serviço (NFS-e) brasileiras.
Extraia APENAS os campos abaixo do documento PDF e retorne um JSON válido,
sem texto extra, sem markdown, somente o JSON.

Campos obrigatórios:
- tomador_cnpj
- tomador_nome
- prestador_cnpj
- prestador_nome
- prestador_endereco
- numero_nota_fiscal
- valor_total_nota_fiscal (number, sem R$)
- data_emissao (DD/MM/YYYY)
- descricao

Se um campo não for encontrado, retorne null para ele.
```

---

## Dependências

```bash
composer require php-open-source-saver/jwt-auth
composer require darkaonline/l5-swagger
```

---

## Variáveis de Ambiente (`.env`)

```env
GEMINI_API_KEY=AIza...          # obtida em aistudio.google.com/apikey
GEMINI_MODEL=gemini-1.5-flash

JWT_SECRET=                     # gerar com: php artisan jwt:secret
JWT_TTL=60
JWT_ALGO=HS256
```

---

## Tratamento de Erros

| Cenário | HTTP | Mensagem |
|---|---|---|
| PDF inválido/corrompido | 422 | "O arquivo enviado não é um PDF válido." |
| LLM não retorna JSON | 502 | "Falha ao processar o documento. Tente novamente." |
| Campos não extraídos | 422 | Lista dos campos ausentes |
| Timeout Gemini API (>30s) | 504 | "Serviço de extração indisponível." |

---

## Como Rodar (Docker)

```bash
# 1. Subir os containers
docker-compose up -d

# 2. Instalar dependências
docker-compose exec app composer install

# 3. Configurar ambiente
docker-compose exec app cp .env.example .env
docker-compose exec app php artisan key:generate
docker-compose exec app php artisan jwt:secret

# 4. Acessar a API
# http://localhost:8080/api/v1/notas-fiscais/extrair

# 5. Documentação Swagger
# http://localhost:8080/api/documentation
```

---

## Fase 2 (Evolução — fora do escopo atual)

- **Queue/Horizon**: Dispatch `ProcessarNotaFiscalJob` → retorna `job_id` imediato
- **Endpoint de status**: `GET /api/v1/notas-fiscais/status/{jobId}`
- **Auditoria**: Owen-IT Auditing nos eventos de extração
- **Cache Redis**: evitar re-processar PDFs idênticos (hash SHA-256 do arquivo)
- **SQL Server**: persistir histórico de extrações
