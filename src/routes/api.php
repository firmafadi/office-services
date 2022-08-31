<?php

use App\Http\Controllers\V1\SendNotificationController;
use App\Http\Controllers\V1\DocumentDraftPdfController;
use App\Http\Controllers\V1\InboxDocumentDownloadController;
use App\Http\Controllers\V1\LoggedUserCheckController;
use App\Http\Controllers\V1\LogUserActivityController;
use App\Http\Controllers\V1\SignaturedDocumentDownloadController;
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

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });
    Route::prefix('v1')->group(function () {
        Route::get('/signatured-documents/{id}/file', [SignaturedDocumentDownloadController::class, '__invoke']);
    });
});

Route::prefix('v1')->group(function () {
    Route::post('/send-notification', [SendNotificationController::class, '__invoke']);
    Route::post('/log-user-activity', [LogUserActivityController::class, '__invoke']);
    Route::get('/draft/{id}', [DocumentDraftPdfController::class, '__invoke']);
    Route::get('/users/{idNumber}/haslogged', [LoggedUserCheckController::class, '__invoke']);
});
