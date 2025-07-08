<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    protected $table = 'v2_user';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'expired_at' => 'timestamp',
        'transfer_enable' => 'integer',
        'device_limit' => 'integer',
        'plan_id' => 'integer',
        'group_id' => 'integer',
        'speed_limit' => 'integer',
        'invite_user_id' => 'integer',
        'last_login_at' => 'timestamp'
    ];
}
