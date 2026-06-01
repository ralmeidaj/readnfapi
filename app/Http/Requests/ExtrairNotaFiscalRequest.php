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
            'pdf'      => ['required', 'file', 'mimes:pdf', 'max:10240'],
            'provider' => ['nullable', 'string', 'in:gemini,openai,mistral'],
        ];
    }

    public function messages(): array
    {
        return [
            'pdf.required'   => 'O arquivo PDF é obrigatório.',
            'pdf.file'       => 'O campo pdf deve ser um arquivo.',
            'pdf.mimes'      => 'O arquivo enviado não é um PDF válido.',
            'pdf.max'        => 'O arquivo não pode ser maior que 10MB.',
            'provider.in'    => 'O provider informado é inválido. Use: gemini, openai ou mistral.',
        ];
    }
}
