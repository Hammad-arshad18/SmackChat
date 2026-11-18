<?php

namespace App\Http\Controllers\Api;

use App\Services\AblyPublisher;
use Illuminate\Http\Request;

class RealtimeController extends ApiController
{
    public function token(Request $request, AblyPublisher $ably)
    {
        $data = $request->validate([
            'workspace' => ['nullable', 'string', 'max:120'],
        ]);

        $workspace = null;

        if (! empty($data['workspace'])) {
            $workspace = $request->user()->workspaces()
                ->where('slug', $data['workspace'])
                ->first();

            abort_unless($workspace, 403, 'You are not a member of that workspace.');
        }

        return response()->json($ably->token($request->user(), $workspace));
    }
}
