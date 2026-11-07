<?php

use App\Http\Controllers\Api\AttachmentController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\ChannelController;
use App\Http\Controllers\Api\InvitationController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\RealtimeController;
use App\Http\Controllers\Api\ScheduleController;
use App\Http\Controllers\Api\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:6,1');
Route::post('/register/verify', [AuthController::class, 'verifyRegistration'])->middleware('throttle:12,1');
Route::post('/register/resend', [AuthController::class, 'resendRegistrationCode'])->middleware('throttle:6,1');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:12,1');
Route::get('/invitations/{token}', [InvitationController::class, 'show']);
Route::post('/invitations/{token}/accept', [InvitationController::class, 'accept']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::patch('/me', [AuthController::class, 'updateProfile']);
    Route::post('/heartbeat', [AuthController::class, 'heartbeat']);
    Route::get('/realtime/token', [RealtimeController::class, 'token']);
    Route::get('/attachments/{attachment}', [AttachmentController::class, 'show']);

    Route::get('/workspaces', [WorkspaceController::class, 'index']);
    Route::post('/workspaces', [WorkspaceController::class, 'store']);

    Route::prefix('workspaces/{workspace}')->middleware('workspace')->group(function () {
        Route::get('/', [WorkspaceController::class, 'show']);
        Route::patch('/', [WorkspaceController::class, 'update']);

        Route::get('/members', [MemberController::class, 'index']);
        Route::post('/invitations', [MemberController::class, 'invite']);
        Route::delete('/invitations/{invitation}', [MemberController::class, 'revokeInvitation']);
        Route::patch('/members/{user}', [MemberController::class, 'updateRole']);
        Route::delete('/members/{user}', [MemberController::class, 'destroy']);

        Route::get('/channels', [ChannelController::class, 'index']);
        Route::post('/channels', [ChannelController::class, 'store']);
        Route::post('/channels/read-all', [ChannelController::class, 'markAllRead']);
        Route::patch('/channels/{channel}', [ChannelController::class, 'update']);
        Route::put('/channels/{channel}/members', [ChannelController::class, 'syncMembers']);
        Route::post('/channels/{channel}/read', [ChannelController::class, 'markRead']);
        Route::post('/dms', [ChannelController::class, 'direct']);

        Route::get('/search', [MessageController::class, 'search']);

        Route::get('/channels/{channel}/messages', [MessageController::class, 'index']);
        Route::post('/channels/{channel}/messages', [MessageController::class, 'store']);
        Route::post('/channels/{channel}/attachments', [AttachmentController::class, 'store']);
        Route::patch('/messages/{message}', [MessageController::class, 'update']);
        Route::delete('/messages/{message}', [MessageController::class, 'destroy']);
        Route::post('/messages/{message}/reactions', [MessageController::class, 'react']);

        Route::get('/schedule', [ScheduleController::class, 'show']);
        Route::put('/schedule', [ScheduleController::class, 'update']);
        Route::post('/holidays', [ScheduleController::class, 'storeHoliday']);
        Route::delete('/holidays/{holiday}', [ScheduleController::class, 'destroyHoliday']);

        Route::get('/attendance/today', [AttendanceController::class, 'today']);
        Route::get('/attendance/floor', [AttendanceController::class, 'floor']);
        Route::post('/attendance/punch', [AttendanceController::class, 'punch']);
        Route::get('/attendance', [AttendanceController::class, 'month']);
        Route::get('/attendance/export', [AttendanceController::class, 'export']);

        Route::post('/billing/archive', [BillingController::class, 'archive']);
        Route::post('/billing/standard', [BillingController::class, 'standard']);
    });
});
