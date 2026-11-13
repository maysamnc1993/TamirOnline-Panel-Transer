<?php

namespace App\Models\Chat;

use Illuminate\Database\Eloquent\Model;

class SavedSpace extends Model
{
    protected $table = 'messenger_saved_spaces';

    protected $guarded = [];

    protected $primaryKey = 'user_id';

    public $incrementing = false;
}
