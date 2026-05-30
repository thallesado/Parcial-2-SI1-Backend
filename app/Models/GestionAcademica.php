<?php

namespace App\Models;

class GestionAcademica extends BaseModel
{
    protected $table = 'admision.gestion_academica';
    protected $primaryKey = 'gestion_id';
    protected $keyType = 'string';
    public $incrementing = false;
}

