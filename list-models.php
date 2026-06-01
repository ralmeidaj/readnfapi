<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$key = config('services.gemini.key');
$r = \Illuminate\Support\Facades\Http::get(
    "https://generativelanguage.googleapis.com/v1beta/models?key={$key}"
);

foreach ($r->json('models', []) as $m) {
    if (in_array('generateContent', $m['supportedGenerationMethods'] ?? [])) {
        echo $m['name'] . PHP_EOL;
    }
}
