<?php

namespace App\Models\Chat;

use Illuminate\Database\Eloquent\Model;

class MessageEdit extends Model
{
    protected $table = 'message_edits';

    protected $guarded = [];

    public $timestamps = false;
}
