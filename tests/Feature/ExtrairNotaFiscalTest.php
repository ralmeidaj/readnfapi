<?php

namespace Tests\Feature;

use App\Contracts\LlmServiceInterface;
use App\Exceptions\ExtratorException;
use App\Services\NotaArtifact;
use App\Services\NotaArtifactResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ExtrairNotaFiscalTest extends TestCase
{
    use RefreshDatabase;

    private array $dadosMock = [
        'tomador_cnpj'            => '12.345.678/0001-90',
        'tomador_cpf'             => null,
        'tomador_nome'            => 'Hospital Exemplo S.A.',
        'prestador_cnpj'          => null,
        'prestador_cpf'           => '123.456.789-00',
        'prestador_nome'          => 'Dr. João Silva',
        'prestador_endereco'      => 'Rua das Flores, 123 - Aracaju/SE',
        'numero_nota_fiscal'      => '000123',
        'valor_total_nota_fiscal' => 15000,
        'data_emissao'            => '28/05/2026',
        'descricao'               => 'Serviços médicos prestados',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_extrai_dados_de_nota_fiscal_com_sucesso(): void
    {
        $this->mock(LlmServiceInterface::class, function ($mock) {
            $mock->shouldReceive('extractFromPdf')
                ->once()
                ->andReturn($this->dadosMock);
        });

        $token = $this->getJwtToken();
        $pdf   = UploadedFile::fake()->create('nota_fiscal.pdf', 500, 'application/pdf');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/notas-fiscais/extrair', ['pdf' => $pdf]);

        $response->assertOk()
            ->assertJsonStructure([
                'output' => [
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
                ],
            ])
            ->assertJsonPath('output.tomador_cnpj', '12.345.678/0001-90')
            ->assertJsonPath('output.valor_total_nota_fiscal', 15000);
    }

    public function test_retorna_422_sem_arquivo(): void
    {
        $token = $this->getJwtToken();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/notas-fiscais/extrair');

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['pdf']);
    }

    public function test_retorna_422_com_arquivo_nao_pdf(): void
    {
        $token = $this->getJwtToken();
        $file  = UploadedFile::fake()->create('documento.txt', 10, 'text/plain');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/notas-fiscais/extrair', ['pdf' => $file]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['pdf']);
    }

    public function test_retorna_401_sem_autenticacao(): void
    {
        $pdf = UploadedFile::fake()->create('nota_fiscal.pdf', 500, 'application/pdf');

        $this->postJson('/api/v1/notas-fiscais/extrair', ['pdf' => $pdf])
            ->assertUnauthorized();
    }

    public function test_retorna_502_quando_llm_falha(): void
    {
        $this->mock(LlmServiceInterface::class, function ($mock) {
            $mock->shouldReceive('extractFromPdf')
                ->once()
                ->andThrow(new ExtratorException('Falha ao processar o documento. Tente novamente.', 502));
        });

        $token = $this->getJwtToken();
        $pdf   = UploadedFile::fake()->create('nota_fiscal.pdf', 500, 'application/pdf');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/notas-fiscais/extrair', ['pdf' => $pdf])
            ->assertStatus(502)
            ->assertJsonPath('message', 'Falha ao processar o documento. Tente novamente.');
    }

    public function test_extrai_dados_de_nota_fiscal_por_url_com_texto_renderizado(): void
    {
        $url = 'https://nfse.salvador.ba.gov.br/site/contribuinte/nota/notaprint.aspx?nf=1084';
        $texto = str_repeat('Nota Fiscal NFS-e Prestador Tomador Valor Emissao ', 20);

        $this->mock(NotaArtifactResolver::class, function ($mock) use ($url, $texto) {
            $mock->shouldReceive('resolve')
                ->once()
                ->with($url)
                ->andReturn(new NotaArtifact(NotaArtifact::TEXT, $texto, $url, 'text/plain'));
        });

        $this->mock(LlmServiceInterface::class, function ($mock) use ($texto) {
            $mock->shouldReceive('extractFromText')
                ->once()
                ->with($texto, \Mockery::type('string'))
                ->andReturn($this->dadosMock);
        });

        $token = $this->getJwtToken();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/notas-fiscais/extrair', ['url' => $url]);

        $response->assertOk()
            ->assertJsonPath('output.numero_nota_fiscal', '000123')
            ->assertJsonPath('output.valor_total_nota_fiscal', 15000);
    }

    public function test_extrai_dados_de_nota_fiscal_por_url_com_pdf_renderizado_quando_texto_for_loading(): void
    {
        $url = 'https://nfse.salvador.ba.gov.br/site/contribuinte/nota/notaprint.aspx?nf=1084';
        $pdf = '%PDF-1.4 conteudo renderizado da nota';

        $this->mock(NotaArtifactResolver::class, function ($mock) use ($url, $pdf) {
            $mock->shouldReceive('resolve')
                ->once()
                ->with($url)
                ->andReturn(new NotaArtifact(NotaArtifact::PDF, $pdf, $url, 'application/pdf'));
        });

        $this->mock(LlmServiceInterface::class, function ($mock) use ($pdf) {
            $mock->shouldReceive('extractFromPdf')
                ->once()
                ->with($pdf, \Mockery::type('string'))
                ->andReturn($this->dadosMock);
        });

        $token = $this->getJwtToken();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/notas-fiscais/extrair', ['url' => $url]);

        $response->assertOk()
            ->assertJsonPath('output.numero_nota_fiscal', '000123')
            ->assertJsonPath('output.valor_total_nota_fiscal', 15000);
    }

    public function test_extrai_dados_de_nota_fiscal_por_url_com_imagem_direto_no_llm(): void
    {
        $url = 'https://nfse.exemplo.gov.br/nota/print';
        $image = 'fake-image-content';

        $this->mock(NotaArtifactResolver::class, function ($mock) use ($url, $image) {
            $artifact = new NotaArtifact(NotaArtifact::IMAGE, $image, $url, 'image/png');

            $mock->shouldReceive('resolve')
                ->once()
                ->with($url)
                ->andReturn($artifact);
            $mock->shouldNotReceive('imageToPdf');
        });

        $this->mock(LlmServiceInterface::class, function ($mock) use ($image) {
            $mock->shouldReceive('extractFromImage')
                ->once()
                ->with($image, 'image/png', \Mockery::type('string'))
                ->andReturn($this->dadosMock);
        });

        $token = $this->getJwtToken();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/notas-fiscais/extrair', ['url' => $url]);

        $response->assertOk()
            ->assertJsonPath('output.numero_nota_fiscal', '000123')
            ->assertJsonPath('output.valor_total_nota_fiscal', 15000);
    }

    private function getJwtToken(): string
    {
        $user = \App\Models\User::factory()->create();
        return auth('api')->login($user);
    }
}
