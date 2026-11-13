<?php

namespace App\Http\Controllers\Api;

use App\Models\Attachment;
use App\Services\ChannelAccess;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AttachmentController extends ApiController
{
    /** Laravel `max` rule for files is kilobytes (20 MB). */
    private const MAX_KILOBYTES = 20480;

    private const EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'webp',
        'mp4', 'webm', 'mov',
        'pdf', 'txt', 'md', 'csv', 'log',
        'doc', 'docx', 'xls', 'xlsx', 'zip',
    ];

    public function store(Request $request, string $workspace, string $channel, ChannelAccess $access)
    {
        $workspaceModel = $this->workspace($request);
        $channelModel = $access->find($workspaceModel, $channel);
        $access->assertCanView($channelModel, $request->user());

        $uploaded = $request->file('file');

        if (! $uploaded) {
            throw ValidationException::withMessages([
                'file' => $this->missingUploadMessage($request),
            ]);
        }

        if (! $uploaded->isValid()) {
            throw ValidationException::withMessages([
                'file' => $this->uploadErrorMessage($uploaded),
            ]);
        }

        $data = $request->validate([
            'file' => ['required', 'file', 'max:'.self::MAX_KILOBYTES],
        ]);

        $file = $data['file'];
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'bin');

        if (! in_array($extension, self::EXTENSIONS, true)) {
            throw ValidationException::withMessages([
                'file' => 'Images, videos, PDFs, documents, and ZIP files are supported.',
            ]);
        }

        $path = $file->storeAs(
            'attachments/'.$workspaceModel->id.'/'.$channelModel->id,
            Str::uuid()->toString().'.'.$extension,
            'local'
        );

        $dimensions = str_starts_with($file->getMimeType(), 'image/')
            ? @getimagesize($file->getRealPath())
            : null;

        $attachment = Attachment::query()->create([
            'channel_id' => $channelModel->id,
            'user_id' => $request->user()->id,
            'disk' => 'local',
            'path' => $path,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 180),
            'extension' => $extension,
            'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
            'size' => $file->getSize(),
            'width' => $dimensions[0] ?? null,
            'height' => $dimensions[1] ?? null,
        ]);

        return response()->json(['attachment' => $attachment->present()], 201);
    }

    public function show(Request $request, Attachment $attachment, ChannelAccess $access)
    {
        $attachment->loadMissing('channel');
        abort_unless($attachment->channel, 404);
        $access->assertCanView($attachment->channel, $request->user());

        abort_unless(Storage::disk($attachment->disk)->exists($attachment->path), 404);

        $inline = $attachment->kind() !== 'file' || $attachment->mime_type === 'application/pdf';
        $etag = '"'.sha1($attachment->id.'|'.$attachment->path.'|'.$attachment->updated_at).'"';

        if ($request->headers->get('If-None-Match') === $etag) {
            return response('', 304, [
                'ETag' => $etag,
                'Cache-Control' => 'private, max-age=604800, immutable',
            ]);
        }

        return Storage::disk($attachment->disk)->response(
            $attachment->path,
            $attachment->original_name,
            [
                'Content-Type' => $attachment->mime_type,
                'Content-Disposition' => ($inline ? 'inline' : 'attachment').'; filename="'.addslashes($attachment->original_name).'"',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, max-age=604800, immutable',
                'ETag' => $etag,
            ]
        );
    }

    private function missingUploadMessage(Request $request): string
    {
        if ($request->header('Content-Type') && ! str_contains((string) $request->header('Content-Type'), 'boundary=')) {
            return 'The upload request was malformed. Refresh the page and try again.';
        }

        $uploadMax = ini_get('upload_max_filesize');
        $postMax = ini_get('post_max_size');

        return 'No file reached the server. If the image is large, the limit may be '.$uploadMax.' (post max '.$postMax.').';
    }

    private function uploadErrorMessage(UploadedFile $file): string
    {
        return match ($file->getError()) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'That file is larger than the server allows ('.ini_get('upload_max_filesize').').',
            UPLOAD_ERR_PARTIAL => 'The upload was interrupted. Try again.',
            UPLOAD_ERR_NO_FILE => 'Choose a file before uploading.',
            default => 'The upload failed. Try a smaller file or another format.',
        };
    }
}
