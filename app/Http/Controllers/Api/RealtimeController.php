<?php

namespace App\Http\Controllers\Api;

use App\Services\AblyPublisher;
use Illuminate\Http\Request;

class RealtimeController extends ApiController
{
    public function token(Request $request, AblyPublisher $ably)
    {
        return response()->json($ably->token($request->user()));
    }
}
