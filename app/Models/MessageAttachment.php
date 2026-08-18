<?php

namespace App\Models;

use Database\Factories\MessageAttachmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'message_id',
    'file_path',
    'original_name',
    'mime_type',
    'file_size',
])]
class MessageAttachment extends Model
{
    /** @use HasFactory<MessageAttachmentFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Message, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    protected function formattedFileSize(): Attribute
    {
        return Attribute::make(
            get: function (): string {
                $kilobytes = $this->file_size / 1024;

                if ($kilobytes >= 1000) {
                    return number_format($kilobytes / 1024, 1).' MB';
                }

                return number_format($kilobytes, 1).' KB';
            },
        );
    }
}
