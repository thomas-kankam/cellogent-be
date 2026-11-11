<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SurveyQuestion extends Model
{
    protected $fillable = [
        'survey_id',
        'prompt',
        'type',
        'options',
        'required',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'required' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function survey()
    {
        return $this->belongsTo(Survey::class, 'survey_id');
    }
}
