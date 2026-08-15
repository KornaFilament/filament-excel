<?php

namespace pxlrbt\FilamentExcel\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'price' => 'decimal:2',
        'is_published' => 'boolean',
        'published_at' => 'datetime',
    ];
}
