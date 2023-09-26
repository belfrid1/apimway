
<?php

use App\v1\User\Http\Controllers\Auth\AuthController;
use Illuminate\Support\Facades\Route;



Route::post('/api/login', [AuthController::class, 'loginUser'])
                ->middleware('guest')
                ->name('login');