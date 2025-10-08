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

    /**
     * 🚀 性能优化：用户关联的计划
     * 解决用户列表查询的O(n²)性能问题
     */
    public function plan()
    {
        return $this->belongsTo(\App\Models\Plan::class, 'plan_id');
    }

    /**
     * 用户关联的邀请人
     * 用于获取邀请人的邮箱等信息
     */
    public function inviter()
    {
        return $this->belongsTo(\App\Models\User::class, 'invite_user_id');
    }
}
