# Extract NF — Contexto para o Claude

## Visão geral

API Laravel 12 que recebe um PDF de Nota Fiscal de Serviço (NFS-e), usa um LLM configurável (Gemini, OpenAI ou Mistral) como motor de OCR + extração inteligente, e retorna os dados estruturados em JSON. Suporta PDFs com texto nativo e PDFs com fontes embutidas (via visão). Não há banco de dados na Fase 1 — a operação é stateless. O ambiente de desenvolvimento roda inteiramente via Docker (PHP 8.4-fpm + Nginx + Redis + poppler-utils).

---

## Plano de Testes

> **Regra:** Após cada alteração ou adição de código, **sempre perguntar ao usuário** se deseja executar o plano antes de rodar qualquer comando de teste.

### Níveis de execução

| Nível | Quando usar | Duração estimada |
|-------|-------------|-----------------|
| **1 — Smoke** | Qualquer mudança de código | ~3s |
| **2 — Unitário** | Mudança em Service ou Request | ~15s |
| **3 — Completo** | Feature nova ou mudança no fluxo | ~70s |
| **4 — Manual** | Mudança no prompt ou integração LLM | variável |

---

### Nível 1 — Smoke (syntax check)

```bash
docker-compose exec app php -l app/Services/GeminiService.php
docker-compose exec app php -l app/Services/OpenAiService.php
docker-compose exec app php -l app/Services/MistralService.php
docker-compose exec app php -l app/Services/NotaFiscalExtratorService.php
docker-compose exec app php -l app/Http/Controllers/Api/V1/NotaFiscalController.php
```

**Resultado esperado:** `No syntax errors detected`

---

### Nível 3 — Completo

```bash
docker-compose exec app php artisan test
```

**Resultado esperado:** 7 testes passando, 0 failed.

---

### Nível 4 — Manual (Insomnia / Postman)

- [ ] `POST /api/v1/notas-fiscais/extrair` sem JWT → 401
- [ ] Sem arquivo → 422 com mensagem PT-BR
- [ ] Com `.txt` → 422 "não é um PDF válido"
- [ ] `provider` inválido → 422 com mensagem de validação
- [ ] PDF com texto nativo → JSON com os 11 campos
- [ ] PDF com fontes embutidas (ex: Nota Salvador) → JSON via visão
- [ ] `valor_total_nota_fiscal` é `number` (não string)
- [ ] `data_emissao` está no formato `DD/MM/YYYY`
- [ ] NF com CPF → `tomador_cpf` ou `prestador_cpf` preenchido, CNPJ = null

---

## Regras de processo

- **Plano de testes obrigatório após toda implementação**: sempre perguntar: *"Quer que eu execute o plano de testes? Se sim, qual nível?"* — nunca executar sem confirmação.
- **Alterações cirúrgicas**: alterar apenas o necessário. Sem refatorações colaterais.
- **Swagger obrigatório**: toda mudança de endpoint deve atualizar as annotations `@OA\*` no controller.
- **Sem comentários de "o quê"**: só comentar o "porquê" não óbvio.
- **Mock da `LlmServiceInterface` nos testes**: nunca chamar a API real. Usar `$this->mock(LlmServiceInterface::class, ...)`.
- **Novo provedor LLM**: implementar `LlmServiceInterface`, registrar no `AppServiceProvider::resolveProvider()` e adicionar ao `in:` do `ExtrairNotaFiscalRequest`.

---

## Comandos essenciais

```bash
# Subir ambiente
docker-compose up -d

# Executar comando artisan
docker-compose exec app php artisan <comando>

# Instalar dependências
docker-compose exec app composer install
docker-compose exec app composer require <pacote>

# Gerar JWT secret
docker-compose exec app php artisan jwt:secret

# Rodar testes
docker-compose exec app php artisan test

# Verificar rotas registradas
docker-compose exec app php artisan route:list

# Gerar documentação Swagger
docker-compose exec app php artisan l5-swagger:generate

# Code style
docker-compose exec app ./vendor/bin/pint

# Logs em tempo real
docker-compose logs -f app

# Derrubar ambiente
docker-compose down
```

---

## Arquitetura

### Fluxo da requisição

```
POST /api/v1/notas-fiscais/extrair
  Body: pdf (file) + provider? (gemini|openai|mistral)
  └── JwtMiddleware (auth:api)
      └── ExtrairNotaFiscalRequest (valida: pdf, provider)
          └── NotaFiscalController::extrair()
              └── NotaFiscalExtratorService::extrair(file, provider?)
                  ├── AppServiceProvider::resolveProvider(provider ?? env)
                  │   └── GeminiService | OpenAiService | MistralService
                  ├── PDF com texto → extractText() → LLM via texto
                  ├── PDF sem texto → pdftoppm → LLM via visão (imagem)
                  ├── json_decode() + validação de campos
                  └── NotaFiscalResource → { "output": { ... } }
```

### Camadas e responsabilidades

| Classe | Responsabilidade |
|--------|-----------------|
| `LlmServiceInterface` | Contrato comum para todos os provedores LLM |
| `GeminiService` | Wrapper Gemini API — envia PDF inline (v1beta) |
| `OpenAiService` | Wrapper OpenAI API — texto ou visão (pdftoppm) |
| `MistralService` | Wrapper Mistral API — texto ou visão (pdftoppm) |
| `NotaFiscalExtratorService` | Regra de negócio — prompt, resolução do provider, validação |
| `AppServiceProvider` | Factory `resolveProvider()` — instancia o serviço correto |
| `NotaFiscalController` | HTTP layer — delega ao service, captura `ExtratorException` |
| `ExtrairNotaFiscalRequest` | Valida pdf + provider com mensagens PT-BR |
| `NotaFiscalResource` | Formata JSON de saída (11 campos) |
| `ExtratorException` | Exceção de domínio com HTTP code embutido |

### Estratégia de leitura de PDF por provedor

| Provedor | PDF com texto | PDF sem texto (fontes embutidas) |
|----------|--------------|----------------------------------|
| `gemini` | inline_data base64 (v1beta) | inline_data base64 (v1beta) |
| `openai` | smalot/pdfparser → texto | pdftoppm → PNG → vision |
| `mistral` | smalot/pdfparser → texto | pdftoppm → PNG → pixtral |

### Tratamento de erros

| Cenário | HTTP |
|---------|------|
| PDF inválido/tipo errado | 422 |
| Provider inválido | 422 |
| PDF sem texto extraível | 422 |
| LLM não retorna JSON | 502 |
| Campos ausentes na resposta | 422 |
| Timeout LLM (>60s) | 504 |
| Rate limit LLM | 429 |

---

## Variáveis de ambiente

| Variável | Obrigatória | Descrição |
|----------|-------------|-----------|
| `LLM_PROVIDER` | Não | Provedor padrão: `gemini`, `openai` ou `mistral` |
| `GEMINI_API_KEY` | Se provider=gemini | Chave da Gemini API |
| `GEMINI_MODEL` | Não | Default: `gemini-3.1-flash-lite` |
| `OPENAI_API_KEY` | Se provider=openai | Chave da OpenAI API |
| `OPENAI_MODEL` | Não | Default: `gpt-5-mini` |
| `MISTRAL_API_KEY` | Se provider=mistral | Chave da Mistral API |
| `MISTRAL_MODEL` | Não | Default: `mistral-small-latest` |
| `MISTRAL_VISION_MODEL` | Não | Default: `pixtral-12b-2409` |
| `JWT_SECRET` | **Sim** | Gerar com `php artisan jwt:secret` |
| `APP_KEY` | **Sim** | Gerar com `php artisan key:generate` |

---

## Endpoints

| Método | Path | Auth | Descrição |
|--------|------|------|-----------|
| `POST` | `/api/v1/notas-fiscais/extrair` | JWT | Extrai dados de NF em PDF |

**Swagger UI:** `http://localhost:8080/api/documentation`

---

## Estrutura de arquivos

```
extract-nf/
├── app/
│   ├── Contracts/
│   │   └── LlmServiceInterface.php        ← interface comum para todos os LLMs
│   ├── Exceptions/
│   │   └── ExtratorException.php
│   ├── Http/
│   │   ├── Controllers/Api/
│   │   │   ├── OpenApiInfo.php            ← @OA\Info e @OA\SecurityScheme
│   │   │   └── V1/NotaFiscalController.php
│   │   ├── Requests/
│   │   │   └── ExtrairNotaFiscalRequest.php  ← valida pdf + provider
│   │   └── Resources/
│   │       └── NotaFiscalResource.php     ← 11 campos (cnpj + cpf)
│   ├── Providers/
│   │   └── AppServiceProvider.php         ← resolveProvider() factory
│   └── Services/
│       ├── GeminiService.php              ← Gemini 3.1 Flash Lite
│       ├── OpenAiService.php              ← GPT-5 Mini + visão
│       ├── MistralService.php             ← Mistral Small + Pixtral
│       └── NotaFiscalExtratorService.php
├── docker/
│   ├── nginx/default.conf
│   └── php/Dockerfile                     ← PHP 8.4 + poppler-utils
├── docker-compose.yml
├── routes/api.php
└── tests/Feature/ExtrairNotaFiscalTest.php  ← 7 testes, mock LlmServiceInterface
```

---

## Convenções de código

- **PHP 8.4**: tipos estritos, `match` em vez de `switch`
- **Laravel Pint** como code style
- **Injeção via `LlmServiceInterface`** — nunca instanciar `GeminiService` diretamente no controller
- **Mensagens de erro em PT-BR** nos `FormRequest::messages()`
- **Sem `try/catch` genérico** — capturar apenas `ExtratorException` no controller

---

## Fase 2 (evolução planejada — fora do escopo atual)

- **Queue/Horizon**: processar PDFs grandes de forma assíncrona, retornar `job_id` imediato
- **Cache Redis**: evitar re-processar PDFs idênticos (hash SHA-256 do arquivo)
- **SQL Server**: persistir histórico de extrações com auditoria (Owen-IT Auditing)
- **Auth via login**: endpoint `POST /api/v1/auth/login` para gerar JWT
- **AdminLTE**: painel para visualizar histórico de extrações
