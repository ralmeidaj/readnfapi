<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ExtrairNotaFiscalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'pdf'      => ['required_without:url', 'nullable', 'file', 'mimes:pdf', 'max:10240'],
            'url'      => ['required_without:pdf', 'nullable', 'url'],
            'provider' => ['nullable', 'string', 'in:gemini,openai,mistral'],
        ];
    }

    public function messages(): array
    {
        return [
            'pdf.required_without' => 'Envie um arquivo PDF ou informe uma URL.',
            'pdf.file'             => 'O campo pdf deve ser um arquivo.',
            'pdf.mimes'            => 'O arquivo enviado não é um PDF válido.',
            'pdf.max'              => 'O arquivo não pode ser maior que 10MB.',
            'url.required_without' => 'Informe uma URL ou envie um arquivo PDF.',
            'url.url'              => 'A URL informada é inválida.',
            'provider.in'          => 'O provider informado é inválido. Use: gemini, openai ou mistral.',
        ];
    }
}
