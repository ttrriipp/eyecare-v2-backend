<?php

namespace App\Http\Controllers;

use App\Models\MessageAttachment;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MessageAttachmentPreviewController extends Controller
{
    public function __invoke(Request $request, MessageAttachment $attachment): StreamedResponse
    {
        abort_unless($request->user()?->canAccessPanel(Filament::getDefaultPanel()), 403);
        abort_unless(
            str_starts_with($attachment->mime_type, 'image/')
                || $attachment->mime_type === 'application/pdf',
            404,
        );

        $disk = Storage::disk((string) config('filesystems.message_attachments_disk', 'message_attachments'));
        abort_unless($disk->exists($attachment->file_path), 404);

        return $disk->response(
            $attachment->file_path,
            $attachment->original_name,
            [
                'Content-Type' => $attachment->mime_type,
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}
