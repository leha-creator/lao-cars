<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\LeadCommentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Комментарий менеджера к заявке (раздел 4 ТЗ).
 */
#[Fillable(['lead_id', 'user_id', 'body'])]
final class LeadComment extends Model
{
    /** @use HasFactory<LeadCommentFactory> */
    use HasFactory;

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
