<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

use App\Http\Controllers\AuthController;
use App\Http\Controllers\GameController;


Route::post('/register', [AuthController::class, 'register']);  
Route::post('/activate', [AuthController::class, 'activate']);  
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/games', [GameController::class, 'createGame']);  
    Route::post('/games/{gameId}/leave', [GameController::class, 'leaveGame']);  
    Route::get('/games/{gameId}', [GameController::class, 'show']);  
    Route::post('/games/{gameId}/guess', [GameController::class, 'guess']);
    Route::get('/games/history/{userId}',[GameController::class, 'showHistoryById']);

    Route::middleware('is_admin')->group(function () {
        Route::put('/admin/deactivate/{userId}', [GameController::class, 'deactivateAccount']);
        
        Route::get('/admin/allhistory', [GameController::class, 'showAllHistory']);
    });
});




