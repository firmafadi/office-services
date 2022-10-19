<?php

use App\Http\Controllers\V1\DepartmentUnitController;
use App\Http\Controllers\V1\DocumentClassifiedController;
use App\Http\Controllers\V1\SendNotificationController;
use App\Http\Controllers\V1\DocumentDraftPdfController;
use App\Http\Controllers\V1\LoggedUserCheckController;
use App\Http\Controllers\V1\LogUserActivityController;
use App\Http\Controllers\V1\DocumentDownloadController;
use App\Http\Controllers\V1\DocumentSignatureFileController;
use App\Http\Controllers\V1\DocumentTypeController;
use App\Http\Controllers\V1\EsignDocumentCheckStatusController;
use App\Http\Controllers\V1\EsignDocumentTypeController;
use App\Http\Controllers\V1\EsignDocumentUploadController;
use App\Http\Controllers\V1\EsignSignerController;
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
        Route::get('/documents/{type}/{id}/download', [DocumentDownloadController::class, '__invoke']);
    });
});

Route::prefix('v1')->group(function () {
    Route::post('/send-notification', [SendNotificationController::class, '__invoke']);
    Route::post('/log-user-activity', [LogUserActivityController::class, '__invoke']);
    Route::get('/draft/{id}', [DocumentDraftPdfController::class, '__invoke']);
    Route::get('/users/{idNumber}/haslogged', [LoggedUserCheckController::class, '__invoke']);
    Route::get('/users/{idNumber}/haslogged', [LoggedUserCheckController::class, '__invoke']);
    Route::get('/units', [DepartmentUnitController::class, '__invoke']);
    Route::get('/document-types', [DocumentTypeController::class, '__invoke']);
    Route::get('/document-classified', [DocumentClassifiedController::class, '__invoke']);
    Route::prefix('esign')->group(function () {
        Route::get('/documents/types', [EsignDocumentTypeController::class, '__invoke']);
        Route::get('/documents/{id}/file', [DocumentSignatureFileController::class, '__invoke']);
        Route::get('/documents', [EsignDocumentCheckStatusController::class, '__invoke']);
        Route::post('/documents', [EsignDocumentUploadController::class, 'upload']);
        Route::get('/signers', [EsignSignerController::class, '__invoke']);
    });
});
