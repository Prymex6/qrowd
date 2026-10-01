<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiUsage extends Model
{
    protected $table = 'api_usage';

    protected $fillable = ['quota_date', 'operation', 'units', 'query', 'party_id'];

    protected function casts(): array
    {
        return ['quota_date' => 'date'];
    }
}
