<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SurveyResponse extends Model
{
    protected $fillable = [
        'survey_id',
        'contact_id',
        'phone',
        'clicked_at',
        'responded_at',
        'answers',
        'reward_status',
        'reward_meta',
    ];

    protected function casts(): array
    {
        return [
            'clicked_at' => 'datetime',
            'responded_at' => 'datetime',
            'answers' => 'array',
            'reward_meta' => 'array',
        ];
    }

    public function survey()
    {
        return $this->belongsTo(Survey::class, 'survey_id');
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }
}
