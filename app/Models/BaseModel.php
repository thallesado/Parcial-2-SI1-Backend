<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

abstract class BaseModel extends Model
{
    public $timestamps = false;
    protected $guarded = [];

    protected function qualify(string $table): string
    {
        return env('DB_SCHEMA', 'admision').'.'.$table;
    }
}

