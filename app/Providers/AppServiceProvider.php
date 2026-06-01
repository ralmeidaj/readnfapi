<?php

namespace App\Providers;

use App\Contracts\LlmServiceInterface;
use App\Services\GeminiService;
use App\Services\MistralService;
use App\Services\OpenAiService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(LlmServiceInterface::class, function () {
            return self::resolveProvider(config('services.llm.provider', 'gemini'));
        });
    }

    public static function resolveProvider(string $provider): LlmServiceInterface
    {
        return match ($provider) {
            'openai'  => new OpenAiService(),
            'mistral' => new MistralService(),
            default   => new GeminiService(),
        };
    }

    public function boot(): void {}
}
