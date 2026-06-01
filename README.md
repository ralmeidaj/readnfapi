<h1 align="center">Extract NF 📄</h1>

<p align="center">
  API Laravel 12 para extração inteligente de dados de Notas Fiscais de Serviço (NFS-e)<br/>
  com suporte a múltiplos provedores de LLM — Gemini, OpenAI e Mistral.
</p>

<p align="center">
  <img alt="PHP" src="https://img.shields.io/badge/PHP-8.4-777BB4?logo=php"/>
  <img alt="Laravel" src="https://img.shields.io/badge/Laravel-12-FF2D20?logo=laravel"/>
  <img alt="Docker" src="https://img.shields.io/badge/Docker-29%2B-2496ED?logo=docker"/>
  <img alt="OpenAI" src="https://img.shields.io/badge/GPT--5_Mini-412991?logo=openai"/>
  <img alt="Gemini" src="https://img.shields.io/badge/Gemini-3.1_Flash-4285F4?logo=google"/>
  <img alt="Mistral" src="https://img.shields.io/badge/Mistral-Small-FF7000"/>
  <img alt="License" src="https://img.shields.io/badge/licença-UNLICENSED-red"/>
</p>

---

## Como funciona

```
PDF da NF  +  provider? (gemini|openai|mistral)
   ↓
API recebe o arquivo (multipart/form-data)
   ↓
PDF com texto nativo?
  ├── Sim → extrai texto → envia ao LLM como texto
  └── Não → converte para imagem (pdftoppm) → envia ao LLM via visão
   ↓
LLM extrai os campos estruturados
   ↓
JSON retornado ao cliente
```

Suporta qualquer NF-e brasileira — texto nativo ou fontes embutidas/escaneadas.

---

## 🚀 Começando

### Pré-requisitos

- [Docker](https://www.docker.com/) 24+
- [Docker Compose](https://docs.docker.com/compose/) V2
- Chave de API de pelo menos um provedor LLM

### Instalação

**1. Clone e entre no diretório:**

```bash
git clone https://github.com/seu-usuario/extract-nf.git
cd extract-nf
```

**2. Configure as variáveis de ambiente:**

```bash
cp .env.example .env
```

Edite o `.env` e configure o provedor desejado:

```env
LLM_PROVIDER=openai          # gemini | openai | mistral

# OpenAI
OPENAI_API_KEY=sk-proj-...
OPENAI_MODEL=gpt-5-mini

# Google Gemini
GEMINI_API_KEY=AIza...
GEMINI_MODEL=gemini-3.1-flash-lite

# Mistral
MISTRAL_API_KEY=...
MISTRAL_MODEL=mistral-small-latest
MISTRAL_VISION_MODEL=pixtral-12b-2409
```

**3. Suba os containers:**

```bash
docker-compose up -d
```

**4. Instale as dependências e configure a aplicação:**

```bash
docker-compose exec app composer install
docker-compose exec app php artisan key:generate
docker-compose exec app php artisan jwt:secret
docker-compose exec app php artisan vendor:publish --provider="PHPOpenSourceSaver\JWTAuth\Providers\LaravelServiceProvider"
docker-compose exec app php artisan l5-swagger:generate
```

**5. Acesse:**

| Recurso | URL |
|---------|-----|
| API | `http://localhost:8080/api/v1/` |
| Swagger UI | `http://localhost:8080/api/documentation` |

---

## 🐳 Serviços Docker

| Serviço | Porta | Descrição |
|---------|-------|-----------|
| `app` | — | PHP 8.4-fpm + poppler-utils |
| `nginx` | **8080** | Servidor web |
| `redis` | **6380** | Cache / Sessão / Fila |

---

## 📡 Endpoints

### `POST /api/v1/notas-fiscais/extrair`

Extrai dados de uma Nota Fiscal em PDF.

**Headers:**
```
Authorization: Bearer <JWT>
Content-Type: multipart/form-data
```

**Body:**
| Campo | Tipo | Obrigatório | Descrição |
|-------|------|-------------|-----------|
| `pdf` | file | Sim | Arquivo PDF da NF (máx. 10MB) |
| `provider` | string | Não | `gemini`, `openai` ou `mistral` (padrão: `.env`) |

**Resposta de sucesso (200):**
```json
{
  "output": {
    "tomador_cnpj": "12.345.678/0001-90",
    "tomador_cpf": null,
    "tomador_nome": "Hospital Exemplo S.A.",
    "prestador_cnpj": null,
    "prestador_cpf": "123.456.789-00",
    "prestador_nome": "Dr. João Silva",
    "prestador_endereco": "Rua das Flores, 123 - Aracaju/SE",
    "numero_nota_fiscal": "000123",
    "valor_total_nota_fiscal": 15000.00,
    "data_emissao": "28/05/2026",
    "descricao": "Serviços médicos prestados..."
  }
}
```

> `tomador_cnpj`/`tomador_cpf` e `prestador_cnpj`/`prestador_cpf` são mutuamente exclusivos — um será preenchido e o outro `null`.

**Respostas de erro:**

| Status | Cenário |
|--------|---------|
| 401 | JWT ausente ou inválido |
| 422 | PDF inválido, provider inválido ou campos não extraídos |
| 429 | Rate limit do provedor LLM atingido |
| 502 | Falha na comunicação com o LLM |
| 504 | Timeout na chamada ao LLM (>60s) |

---

## 🤖 Provedores LLM

| Provider | Modelo texto | Modelo visão | PDF complexo |
|----------|-------------|-------------|-------------|
| `gemini` | gemini-3.1-flash-lite | gemini-3.1-flash-lite | ✅ inline |
| `openai` | gpt-5-mini | gpt-5-mini | ✅ pdftoppm |
| `mistral` | mistral-small-latest | pixtral-12b-2409 | ✅ pdftoppm |

---

## ⚙️ Executando os Testes

```bash
# Todos os testes
docker-compose exec app php artisan test

# Apenas extração de NF
docker-compose exec app php artisan test --filter ExtrairNotaFiscalTest
```

**Resultado esperado:** 7 testes passando (mock do `LlmServiceInterface` — sem chamadas reais à API).

### Checklist manual

- [ ] Sem JWT → 401
- [ ] Sem arquivo → 422 PT-BR
- [ ] Arquivo `.txt` → 422 "não é um PDF válido"
- [ ] `provider=invalido` → 422
- [ ] PDF com texto nativo → 200 com campos preenchidos
- [ ] PDF com fontes embutidas → 200 via visão
- [ ] NF com CPF → campo `cpf` preenchido, `cnpj` = null

---

## 📜 Scripts Úteis

```bash
# Subir / derrubar
docker-compose up -d
docker-compose down

# Artisan
docker-compose exec app php artisan route:list
docker-compose exec app php artisan test
docker-compose exec app php artisan l5-swagger:generate
docker-compose exec app php artisan config:clear

# Code style (Laravel Pint)
docker-compose exec app ./vendor/bin/pint

# Gerar JWT para testes (tinker)
docker-compose exec app php artisan tinker
# >>> $u = App\Models\User::first(); echo auth('api')->login($u);

# Logs
docker-compose logs -f app
```

---

## 🛠️ Stack

| Camada | Tecnologia |
|--------|-----------|
| Linguagem | PHP 8.4 |
| Framework | Laravel 12 |
| LLM providers | Gemini 3.1 · GPT-5 Mini · Mistral Small + Pixtral |
| PDF texto | `smalot/pdfparser` |
| PDF visão | `poppler-utils` (pdftoppm) |
| HTTP Client | Laravel Http (Guzzle) |
| OpenAI client | `openai-php/client` |
| Auth API | JWT HS256 (`php-open-source-saver/jwt-auth`) |
| Documentação | Swagger OpenAPI 3.0 (`darkaonline/l5-swagger`) |
| Testes | PHPUnit (via `php artisan test`) |
| Code Style | Laravel Pint |
| Infraestrutura | Docker (PHP 8.4-fpm + Nginx + Redis) |

---

## 🗺️ Roadmap

| Fase | Status |
|------|--------|
| API síncrona multi-provider (Gemini/OpenAI/Mistral) | ✅ Fase 1 |
| Suporte a CPF e CNPJ | ✅ Fase 1 |
| PDFs com fontes embutidas (visão) | ✅ Fase 1 |
| Parâmetro `provider` por requisição | ✅ Fase 1 |
| Swagger UI completo | ✅ Fase 1 |
| 7 testes automatizados | ✅ Fase 1 |
| Queue/Horizon para PDFs grandes | 🔜 Fase 2 |
| Cache Redis (SHA-256 do arquivo) | 🔜 Fase 2 |
| Histórico de extrações (SQL Server) | 🔜 Fase 2 |
| Auth via login (endpoint JWT) | 🔜 Fase 2 |
| AdminLTE 3 — painel de extrações | 🔜 Fase 2 |
| Auditoria (Owen-IT Auditing) | 🔜 Fase 2 |

---

## 📦 Implantação

- `APP_KEY` e `JWT_SECRET` são **obrigatórios** — nunca expor publicamente
- `APP_DEBUG=false` em produção
- Chaves de API dos LLMs devem ser variáveis de ambiente (nunca no git)
- O arquivo `.env` nunca deve ser commitado

---

## ✒️ Autores

- **Raimundo Araújo** — *Desenvolvimento*

---

## 📄 Licença

Este projeto é de uso privado e não possui licença pública.

---

<p align="center">Feito com PHP 🐘 · Gemini ✨ · GPT 🤖 · Mistral 🌪️</p>
