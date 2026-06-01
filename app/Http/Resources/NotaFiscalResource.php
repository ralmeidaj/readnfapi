<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotaFiscalResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'output' => [
                'tomador_cnpj'            => $this->resource['tomador_cnpj'],
                'tomador_cpf'             => $this->resource['tomador_cpf'],
                'tomador_nome'            => $this->resource['tomador_nome'],
                'prestador_cnpj'          => $this->resource['prestador_cnpj'],
                'prestador_cpf'           => $this->resource['prestador_cpf'],
                'prestador_nome'          => $this->resource['prestador_nome'],
                'prestador_endereco'      => $this->resource['prestador_endereco'],
                'numero_nota_fiscal'      => $this->resource['numero_nota_fiscal'],
                'valor_total_nota_fiscal' => $this->resource['valor_total_nota_fiscal'],
                'data_emissao'            => $this->resource['data_emissao'],
                'descricao'               => $this->resource['descricao'],
            ],
        ];
    }
}
