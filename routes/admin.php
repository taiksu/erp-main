<?php

use App\Http\Middleware\CheckFranqueadora;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Rotas protegidas
Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
    CheckFranqueadora::class
])->group(function () {

    Route::get('/franqueadora/painel', function () {
        return Inertia::render('Admin/Painel/Index');
    })->name('franqueadora.painel');

    Route::get('/franqueadora/email', function () {
        return Inertia::render('Admin/Email/Index');
    })->name('franqueadora.email');

    Route::get('/franqueadora/comunidade', function () {
        return Inertia::render('Admin/Comunidade/Index');
    })->name('franqueadora.comunidade');

    Route::get('/franqueadora/midias', function () {
        return Inertia::render('Admin/Midias/Index');
    })->name('franqueadora.midias');

    Route::get('/franqueadora/megafone', function () {
        return Inertia::render('Admin/Megafone/Index');
    })->name('franqueadora.megafone');

    Route::get('/franqueadora/franqueados', function () {
        return Inertia::render('Admin/Franqueados/Index');
    })->name('franqueadora.franqueados');

    Route::get('/franqueadora/unidades', function () {
        return Inertia::render('Admin/Unidades/Index');
    })->name('franqueadora.unidades');

    Route::get('/franqueadora/fornecedores', function () {
        return Inertia::render('Admin/Fornecedores/Index');
    })->name('franqueadora.fornecedores');

    Route::get('/franqueadora/insumos', function () {
        return Inertia::render('Admin/Insumos/Index');
    })->name('franqueadora.insumos');

    Route::get('/franqueadora/inspetor', function () {
        return Inertia::render('Admin/Inspetor/Index');
    })->name('franqueadora.inspetor');

    // 
    Route::get('/franqueadora/metodos-pagamentos', function () {
        return Inertia::render('Admin/MetodosPagamentos/Index');
    })->name('franqueadora.metodosPagamentos');

    Route::get('/franqueadora/canais-vendas', function () {
        return Inertia::render('Admin/CanaisVendas/Index');
    })->name('franqueadora.canaisVendas');

    // 

    Route::get('/franqueadora/sair', function () {
        return Inertia::render('Admin/sair/Index');
    })->name('franqueadora.sair');
});
