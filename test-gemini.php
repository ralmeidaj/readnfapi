<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$key   = config('services.gemini.key');
$model = config('services.gemini.model');

echo "Modelo: $model\n";
echo "Key: " . substr($key, 0, 15) . "...\n\n";

$response = \Illuminate\Support\Facades\Http::post(
    "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$key}",
    ['contents' => [['parts' => [['text' => 'Responda apenas com a palavra: OK']]]]]
);

echo "Status: " . $response->status() . "\n";
echo "Body: " . $response->body() . "\n";
