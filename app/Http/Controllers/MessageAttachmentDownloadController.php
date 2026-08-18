<?php

namespace App\Http\Controllers;

use App\Models\MessageAttachment;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MessageAttachmentDownloadController extends Controller
{
    public function __invoke(Request $request, MessageAttachment $attachment): StreamedResponse
    {
        abort_unless($request->user()?->canAccessPanel(Filament::getDefaultPanel()), 403);
        abort_unless(Storage::disk('local')->exists($attachment->file_path), 404);

        return Storage::disk('local')->download(
            $attachment->file_path,
            $attachment->original_name,
            [
                'Content-Type' => $attachment->mime_type,
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}
