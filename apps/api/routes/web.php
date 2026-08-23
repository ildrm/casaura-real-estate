<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect('/api/v1/health'));
