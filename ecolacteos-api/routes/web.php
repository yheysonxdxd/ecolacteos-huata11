<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Web de oficina (Admin y Compras): usa las mismas rutas /api/v1 que la app.
Route::view('/oficina', 'oficina');
