<?php

use App\Http\Controllers\Api\AttachmentController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\CallController;
use App\Http\Controllers\Api\ChannelController;
use App\Http\Controllers\Api\E2eController;
use App\Http\Controllers\Api\InvitationController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\PushController;
use App\Http\Controllers\Api\RealtimeController;
use App\Http\Controllers\Api\RealtimeSettingsController;
use App\Http\Controllers\Api\ScheduleController;
use App\Http\Controllers\Api\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:6,1');
Route::post('/register/verify', [AuthController::class, 'verifyRegistration'])->middleware('throttle:12,1');
Route::post('/register/resend', [AuthController::class, 'resendRegistrationCode'])->middleware('throttle:6,1');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:6,1');
Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:6,1');
Route::get('/invitations/{token}', [InvitationController::class, 'show']);
Route::post('/invitations/{token}/accept', [InvitationController::class, 'accept']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/e2e/devices', [E2eController::class, 'device'])->middleware('throttle:presence');
    Route::get('/e2e/vault', [E2eController::class, 'showVault']);
    Route::put('/e2e/vault', [E2eController::class, 'updateVault'])->middleware('throttle:presence');
    Route::get('/e2e/vault/channels/{channel}', [E2eController::class, 'showBackup'])->whereNumber('channel');
    Route::put('/e2e/vault/channels', [E2eController::class, 'updateBackups'])->middleware('throttle:presence');
    Route::get('/me', [AuthController::class, 'me']);
    Route::patch('/me', [AuthController::class, 'updateProfile']);
    Route::get('/me/export', [AuthController::class, 'export'])->middleware('throttle:exports');
    Route::delete('/me', [AuthController::class, 'destroy']);
    Route::post('/me/avatar', [AuthController::class, 'updateAvatar']);
    Route::delete('/me/avatar', [AuthController::class, 'destroyAvatar']);
    Route::get('/avatars/{user}', [AuthController::class, 'avatar']);
    Route::post('/heartbeat', [AuthController::class, 'heartbeat'])->middleware('throttle:presence');
    Route::get('/realtime/token', [RealtimeController::class, 'token']);
    Route::get('/push/key', [PushController::class, 'key']);
    Route::put('/push/subscription', [PushController::class, 'store']);
    Route::delete('/push/subscription', [PushController::class, 'destroy']);
    Route::post('/push/test', [PushController::class, 'test'])->middleware('throttle:presence');
    Route::get('/attachments/{attachment}', [AttachmentController::class, 'show'])->middleware('throttle:attachments');

    Route::get('/workspaces', [WorkspaceController::class, 'index']);
    Route::post('/workspaces', [WorkspaceController::class, 'store'])->middleware('throttle:presence');

    Route::prefix('workspaces/{workspace}')->middleware('workspace')->group(function () {
        Route::get('/', [WorkspaceController::class, 'show']);
        Route::patch('/', [WorkspaceController::class, 'update']);
        Route::get('/logo', [WorkspaceController::class, 'logo']);
        Route::post('/logo', [WorkspaceController::class, 'updateLogo']);
        Route::delete('/logo', [WorkspaceController::class, 'destroyLogo']);
        Route::get('/audit', [AuditController::class, 'index']);
        Route::put('/realtime', [RealtimeSettingsController::class, 'update']);
        Route::delete('/realtime', [RealtimeSettingsController::class, 'destroy']);

        Route::get('/calls/active', [CallController::class, 'active']);
        Route::post('/calls', [CallController::class, 'store'])->middleware('throttle:calls');
        Route::get('/calls/{call}', [CallController::class, 'show'])->whereNumber('call');
        Route::post('/calls/{call}/join', [CallController::class, 'join'])->whereNumber('call')->middleware('throttle:calls');
        Route::post('/calls/{call}/leave', [CallController::class, 'leave'])->whereNumber('call');
        Route::post('/calls/{call}/signal', [CallController::class, 'signal'])->whereNumber('call')->middleware('throttle:calls');
        Route::get('/calls/{call}/signals', [CallController::class, 'signals'])->whereNumber('call')->middleware('throttle:calls');

        Route::get('/members', [MemberController::class, 'index']);
        Route::post('/invitations', [MemberController::class, 'invite'])->middleware('throttle:presence');
        Route::delete('/invitations/{invitation}', [MemberController::class, 'revokeInvitation']);
        Route::patch('/members/{user}', [MemberController::class, 'updateRole']);
        Route::delete('/members/{user}', [MemberController::class, 'destroy']);

        Route::get('/channels', [ChannelController::class, 'index']);
        Route::post('/channels', [ChannelController::class, 'store'])->middleware('throttle:presence');
        Route::post('/channels/read-all', [ChannelController::class, 'markAllRead']);
        Route::patch('/channels/{channel}', [ChannelController::class, 'update']);
        Route::put('/channels/{channel}/members', [ChannelController::class, 'syncMembers']);
        Route::post('/channels/{channel}/read', [ChannelController::class, 'markRead']);
        Route::post('/dms', [ChannelController::class, 'direct'])->middleware('throttle:presence');
        Route::get('/channels/{channel}/keys', [E2eController::class, 'show']);
        Route::put('/channels/{channel}/keys', [E2eController::class, 'update']);

        Route::get('/search', [MessageController::class, 'search'])->middleware('throttle:search');

        Route::get('/channels/{channel}/messages', [MessageController::class, 'index']);
        Route::post('/channels/{channel}/messages', [MessageController::class, 'store'])->middleware('throttle:messages');
        Route::post('/channels/{channel}/attachments', [AttachmentController::class, 'store'])->middleware('throttle:uploads');
        Route::patch('/messages/{message}', [MessageController::class, 'update'])->middleware('throttle:messages');
        Route::delete('/messages/{message}', [MessageController::class, 'destroy'])->middleware('throttle:messages');
        Route::post('/messages/{message}/reactions', [MessageController::class, 'react'])->middleware('throttle:messages');

        Route::get('/schedule', [ScheduleController::class, 'show']);
        Route::put('/schedule', [ScheduleController::class, 'update'])->middleware('throttle:presence');
        Route::post('/holidays', [ScheduleController::class, 'storeHoliday']);
        Route::delete('/holidays/{holiday}', [ScheduleController::class, 'destroyHoliday']);

        Route::get('/attendance/today', [AttendanceController::class, 'today']);
        Route::get('/attendance/floor', [AttendanceController::class, 'floor']);
        Route::post('/attendance/punch', [AttendanceController::class, 'punch'])->middleware('throttle:presence');
        Route::get('/attendance', [AttendanceController::class, 'month']);
        Route::get('/attendance/export', [AttendanceController::class, 'export'])->middleware('throttle:exports');

        Route::post('/billing/archive', [BillingController::class, 'archive']);
        Route::post('/billing/standard', [BillingController::class, 'standard']);
    });
});
