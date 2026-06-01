<?php

use App\Http\Controllers\Api\V1\NotaFiscalController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('auth:api')->group(function () {
    Route::post('notas-fiscais/extrair', [NotaFiscalController::class, 'extrair']);
});
