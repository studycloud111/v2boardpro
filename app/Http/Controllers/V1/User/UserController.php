<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\UserChangePassword;
use App\Http\Requests\User\UserRedeemGiftCard;
use App\Http\Requests\User\UserTransfer;
use App\Http\Requests\User\UserUpdate;
use App\Models\Giftcard;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AuthService;
use App\Services\OrderService;
use App\Services\UserService;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
    public function getActiveSession(Request $request)
    {
        $user = User::find($request->user['id']);
        if (!$user) {
            abort(500, __('The user does not exist'));
        }
        $authService = new AuthService($user);
        return response([
            'data' => $authService->getSessions()
        ]);
    }

    public function removeActiveSession(Request $request)
    {
        $user = User::find($request->user['id']);
        if (!$user) {
            abort(500, __('The user does not exist'));
        }
        $authService = new AuthService($user);
        return response([
            'data' => $authService->removeSession($request->input('session_id'))
        ]);
    }

    public function checkLogin(Request $request)
    {
        $data = [
            'is_login' => $request->user['id'] ? true : false
        ];
        if ($request->user['is_admin']) {
            $data['is_admin'] = true;
        }
        return response([
            'data' => $data
        ]);
    }

    public function changePassword(UserChangePassword $request)
    {
        $user = User::find($request->user['id']);
        if (!$user) {
            abort(500, __('The user does not exist'));
        }
        if (!Helper::multiPasswordVerify(
            $user->password_algo,
            $user->password_salt,
            $request->input('old_password'),
            $user->password
        )) {
            abort(500, __('The old password is wrong'));
        }
        $user->password = password_hash($request->input('new_password'), PASSWORD_DEFAULT);
        $user->password_algo = NULL;
        $user->password_salt = NULL;
        if (!$user->save()) {
            abort(500, __('Save failed'));
        }
        $authService = new AuthService($user);
        $authService->removeAllSession();
        return response([
            'data' => true
        ]);
    }

    public function newPeriod(Request $request) 
    {
        if (!config('v2board.allow_new_period', 0)) {
            abort(500, __('Renewal is not allowed'));
        }
        DB::beginTransaction();
        try {
            $user = User::find($request->user['id']);
            if (!$user) {
                abort(500, __('The user does not exist'));
            }
            if ($user->transfer_enable > $user->u + $user->d) {
                abort(500, __('You have not used up your traffic, you cannot renew your subscription'));
            }
            $userService = new UserService();
            $reset_day = $userService->getResetDay($user);
            if ($reset_day === null) {
                abort(500, __('You do not allow to renew the subscription'));
            }
            unset($user->plan);
            $reset_period = $userService->getResetPeriod($user);
            if ($reset_period === null) {
                abort(500, __('You do not allow to renew the subscription'));
            }
            switch ($reset_period) {
                case 1:
                    $reset_day = 30;
                    $reset_period = 30;
                    break;
                case 30:
                    break;
                case 12:
                    $reset_day = 365;
                    $reset_period = 365;
                    break;
                case 365:
                    break;
                default:
                    abort(500, __('Invalid reset period'));
            }
            if ($reset_day <= 0) {
                $reset_day = $reset_period;
            }
            if ($user->expired_at !== null && ($reset_period + 1) * 86400 < $user->expired_at - time()) {
                if (!$user->update(
                    [
                        'expired_at' => $user->expired_at - $reset_day * 86400,
                        'u' => 0,
                        'd' => 0
                    ]
                )) {
                    throw new \Exception(__('Save failed'));
                }
            } else {
                abort(500, __('You do not have enough time to renew your subscription'));
            }

            DB::commit();
            return response([
                'data' => true
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            abort(500, $e->getMessage());
        }
    }

    public function redeemgiftcard(UserRedeemGiftCard $request)
    {
        DB::beginTransaction();

        try {
            $user = User::where('id', $request->user['id'])->lockForUpdate()->first();
            if (!$user) {
                abort(500, __('The user does not exist'));
            }
            $giftcard_input = $request->input('promo_code', $request->input('giftcard'));
            $giftcard = Giftcard::where('code', $giftcard_input)->lockForUpdate()->first();

            if (!$giftcard) {
                abort(500, __('The gift card does not exist'));
            }

            $currentTime = time();
            if ($giftcard->started_at && $currentTime < $giftcard->started_at) {
                abort(500, __('The gift card is not yet valid'));
            }

            if ($giftcard->ended_at && $currentTime > $giftcard->ended_at) {
                abort(500, __('The gift card has expired'));
            }

            if ($giftcard->limit_use !== null) {
                if (!is_numeric($giftcard->limit_use) || $giftcard->limit_use <= 0) {
                    abort(500, __('The gift card usage limit has been reached'));
                }
            }

            if (DB::table('v2_giftcard_user')->where('giftcard_id', $giftcard->id)->where('user_id', $user->id)->exists()) {
                abort(500, __('The gift card has already been used by this user'));
            }

            // 强制转换为整数以确保类型一致性
            $giftcardType = (int)$giftcard->type;
            switch ($giftcardType) {
                case 1:
                    $user->balance += $giftcard->value;
                    break;
                case 2:
                    if ($user->expired_at !== null) {
                        if ($user->expired_at <= $currentTime) {
                            $user->expired_at = $currentTime + $giftcard->value * 86400;
                        } else {
                            $user->expired_at += $giftcard->value * 86400;
                        }
                    } else {
                        abort(500, __('Not suitable gift card type'));
                    }
                    break;
                case 3:
                    $user->transfer_enable += $giftcard->value * 1073741824;
                    break;
                case 4:
                    $user->u = 0;
                    $user->d = 0;
                    break;
                case 5:
                    // 套餐类礼品卡：智能处理 - 相同套餐延长时间，不同套餐覆盖
                    if (!$giftcard->plan_id) {
                        abort(500, __('Gift card plan ID is missing'));
                    }
                    
                    $plan = Plan::where('id', $giftcard->plan_id)->first();
                    if (!$plan) {
                        abort(500, __('The plan associated with this gift card no longer exists'));
                    }
                    
                    // 检查是否为相同套餐
                    if ($user->plan_id == $giftcard->plan_id) {
                        // 相同套餐：延长时间
                        if ($giftcard->value > 0) {
                            if ($user->expired_at !== null) {
                                if ($user->expired_at <= $currentTime) {
                                    // 套餐已过期，从当前时间开始计算
                                    $user->expired_at = $currentTime + $giftcard->value * 86400;
                                } else {
                                    // 套餐未过期，在现有基础上延长
                                    $user->expired_at += $giftcard->value * 86400;
                                }
                            } else {
                                // 永久套餐保持永久
                                $user->expired_at = null;
                            }
                        }
                        // 相同套餐时不重置流量和其他设置，只延长时间
                    } else {
                        // 不同套餐：完全覆盖
                        $user->plan_id = $plan->id;
                        $user->group_id = $plan->group_id;
                        $user->transfer_enable = $plan->transfer_enable * 1073741824;
                        $user->device_limit = $plan->device_limit;
                        $user->u = 0; // 重置已用流量
                        $user->d = 0;
                        if($giftcard->value == 0) {
                            $user->expired_at = null; // 永久套餐
                        } else {
                            $user->expired_at = $currentTime + $giftcard->value * 86400;
                        }
                    }
                    break;
                default:
                    abort(500, __('Unknown gift card type'));
            }

            if ($giftcard->limit_use !== null) {
                $giftcard->limit_use -= 1;
            }

            DB::table('v2_giftcard_user')->insert([
                'giftcard_id' => $giftcard->id,
                'user_id' => $user->id,
                'created_at' => date('Y-m-d H:i:s', $currentTime),
                'updated_at' => date('Y-m-d H:i:s', $currentTime)
            ]);

            if (!$user->save() || !$giftcard->save()) {
                throw new \Exception(__('Save failed'));
            }

            DB::commit();

            return response([
                'data' => true,
                'type' => $giftcard->type,
                'value' => $giftcard->value
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            abort(500, $e->getMessage());
        }
    }

    /**
     * 代理兑换礼品卡的请求，以绕过WAF
     */
    public function processCode(UserRedeemGiftCard $request)
    {
        return $this->redeemgiftcard($request);
    }

    public function getGiftcardInfo(Request $request)
    {
        $giftcardCode = $request->input('code');
        if (!$giftcardCode) {
            abort(400, __('Gift card code is required'));
        }

        $giftcard = Giftcard::where('code', $giftcardCode)->first();
        if (!$giftcard) {
            abort(404, __('The gift card does not exist'));
        }

        // 检查礼品卡有效性（使用与兑换相同的逻辑）
        $currentTime = time();
        $isValid = true;
        $message = '';

        if ($giftcard->started_at && $currentTime < $giftcard->started_at) {
            $isValid = false;
            $message = __('The gift card is not yet valid');
        } elseif ($giftcard->ended_at && $currentTime > $giftcard->ended_at) {
            $isValid = false;
            $message = __('The gift card has expired');
        } elseif ($giftcard->limit_use !== null && (!is_numeric($giftcard->limit_use) || $giftcard->limit_use <= 0)) {
            $isValid = false;
            $message = __('The gift card usage limit has been reached');
        }

        // 检查用户是否已经使用过此礼品卡（与兑换功能保持一致）
        $user = User::find($request->user['id']);
        $alreadyUsed = DB::table('v2_giftcard_user')
            ->where('giftcard_id', $giftcard->id)
            ->where('user_id', $user->id)
            ->exists();

        if ($alreadyUsed) {
            $isValid = false;
            $message = __('The gift card has already been used by this user');
        }

        // 计算剩余使用次数
        $remainingUses = null;
        if ($giftcard->limit_use !== null) {
            $remainingUses = max(0, $giftcard->limit_use);
        }

        // 格式化礼品卡类型和价值（与兑换功能保持一致）
        $types = ['', '金额', '时长', '流量', '重置', '套餐'];
        $type = $types[$giftcard->type] ?? '未知';
        
        $formattedValue = '';
        // 强制转换为整数以确保类型一致性
        $giftcardType = (int)$giftcard->type;
        switch ($giftcardType) {
            case 1: // 金额
                $formattedValue = round($giftcard->value / 100, 2) . ' ' . config('v2board.currency_symbol', '¥');
                break;
            case 2: // 时长
                // 时长类礼品卡：需要有现有套餐才能延长
                if ($user->expired_at === null) {
                    $isValid = false;
                    $message = __('Time gift cards require an existing plan');
                } else {
                    $formattedValue = $giftcard->value . ' 天';
                }
                break;
            case 3: // 流量
                $formattedValue = $giftcard->value . ' GB';
                break;
            case 4: // 重置
                $formattedValue = '重置套餐';
                break;
            case 5: // 套餐
                // 套餐类礼品卡：直接覆盖当前套餐（与兑换功能保持一致）
                if (!$giftcard->plan_id) {
                    $isValid = false;
                    $message = __('Gift card plan ID is missing') . " (plan_id: null)";
                    break;
                }
                
                $plan = Plan::where('id', $giftcard->plan_id)->first();
                if ($plan) {
                    if ($giftcard->value == 0) {
                        $formattedValue = $plan->name . ' (永久)';
                    } else {
                        $formattedValue = $plan->name . ' (' . $giftcard->value . ' 天)';
                    }
                } else {
                    // 获取所有可用套餐ID用于调试
                    $availablePlanIds = Plan::pluck('id')->toArray();
                    $isValid = false;
                    $message = __('The plan associated with this gift card no longer exists') . " (plan_id: {$giftcard->plan_id}, available_ids: " . implode(',', $availablePlanIds) . ")";
                }
                break;
            default:
                $formattedValue = $giftcard->value;
        }

        return response([
            'data' => [
                'name' => $giftcard->name,
                'type' => $type,
                'type_id' => $giftcard->type,
                'value' => $giftcard->value,
                'formatted_value' => $formattedValue,
                'started_at' => $giftcard->started_at ? date('Y-m-d H:i:s', $giftcard->started_at) : null,
                'ended_at' => $giftcard->ended_at ? date('Y-m-d H:i:s', $giftcard->ended_at) : null,
                'remaining_uses' => $remainingUses,
                'is_valid' => $isValid,
                'message' => $message,
                'already_used' => $alreadyUsed,
                'plan_id' => $giftcard->plan_id, // 套餐类礼品卡的套餐ID
            ]
        ]);
    }

    public function info(Request $request)
    {
        $user = User::where('id', $request->user['id'])
            ->select([
                'email',
                'transfer_enable',
                'device_limit',
                'last_login_at',
                'created_at',
                'banned',
                'auto_renewal',
                'remind_expire',
                'remind_traffic',
                'expired_at',
                'balance',
                'commission_balance',
                'plan_id',
                'discount',
                'commission_rate',
                'telegram_id',
                'uuid'
            ])
            ->first();
        if (!$user) {
            abort(500, __('The user does not exist'));
        }
        $user['avatar_url'] = 'https://cravatar.cn/avatar/' . md5($user->email) . '?s=64&d=identicon';
        return response([
            'data' => $user
        ]);
    }

    public function getStat(Request $request)
    {
        $stat = [
            Order::where('status', 0)
                ->where('user_id', $request->user['id'])
                ->count(),
            Ticket::where('status', 0)
                ->where('user_id', $request->user['id'])
                ->count(),
            User::where('invite_user_id', $request->user['id'])
                ->count()
        ];
        return response([
            'data' => $stat
        ]);
    }

    public function getSubscribe(Request $request)
    {
        $user = User::where('id', $request->user['id'])
            ->select([
                'plan_id',
                'token',
                'expired_at',
                'u',
                'd',
                'transfer_enable',
                'device_limit',
                'email',
                'uuid'
            ])
            ->first();
        if (!$user) {
            abort(500, __('The user does not exist'));
        }
        if ($user->plan_id) {
            $user['plan'] = Plan::find($user->plan_id);
            if (!$user['plan']) {
                abort(500, __('Subscription plan does not exist'));
            }
        }

        //统计在线设备
        $countalive = 0;
        $ips_array = Cache::get('ALIVE_IP_USER_' . $request->user['id']);
        if ($ips_array) {
            $countalive = $ips_array['alive_ip'];
        }
        $user['alive_ip'] = $countalive;

        $user['subscribe_url'] = Helper::getSubscribeUrl($user['token']);

        $userService = new UserService();
        $user['reset_day'] = $userService->getResetDay($user);
        $user['allow_new_period'] = config('v2board.allow_new_period', 0);
        return response([
            'data' => $user
        ]);
    }

    public function unbindTelegram(Request $request)
    {
        $user = User::find($request->user['id']);
        if (!$user) {
            abort(500, __('The user does not exist'));
        }
        if (!$user->update(['telegram_id' => null])) {
            abort(500, __('Unbind telegram failed'));
        }
        return response([
            'data' => true
        ]);
    }

    public function resetSecurity(Request $request)
    {
        $user = User::find($request->user['id']);
        if (!$user) {
            abort(500, __('The user does not exist'));
        }
        $user->uuid = Helper::guid(true);
        $user->token = Helper::guid();
        if (!$user->save()) {
            abort(500, __('Reset failed'));
        }
        return response([
            'data' => Helper::getSubscribeUrl($user['token'])
        ]);
    }

    public function update(UserUpdate $request)
    {
        $updateData = $request->only([
            'auto_renewal',
            'remind_expire',
            'remind_traffic'
        ]);

        $user = User::find($request->user['id']);
        if (!$user) {
            abort(500, __('The user does not exist'));
        }
        try {
            $user->update($updateData);
        } catch (\Exception $e) {
            abort(500, __('Save failed'));
        }

        return response([
            'data' => true
        ]);
    }

    public function transfer(UserTransfer $request)
    {
        $user = User::find($request->user['id']);
        if (!$user) {
            abort(500, __('The user does not exist'));
        }
        if ($request->input('transfer_amount') > $user->commission_balance) {
            abort(500, __('Insufficient commission balance'));
        }
        DB::beginTransaction();
        $order = new Order();
        $orderService = new OrderService($order);
        $order->user_id = $request->user['id'];
        $order->plan_id = 0;
        $order->period = 'deposit';
        $order->trade_no = Helper::generateOrderNo();
        $order->total_amount = $request->input('transfer_amount');

        $orderService->setOrderType($user);
        $orderService->setInvite($user);

        $user->commission_balance = $user->commission_balance - $request->input('transfer_amount');
        $user->balance = $user->balance + $request->input('transfer_amount');
        $order->status = 3;
        $order->total_amount = 0;
        $order->surplus_amount = $request->input('transfer_amount');
        $order->callback_no = '佣金划转 Commission transfer';
        if (!$order->save()||!$user->save()) {
            DB::rollback();
            abort(500, __('Transfer failed'));
        }

        DB::commit();

        return response([
            'data' => true
        ]);
    }

    public function getQuickLoginUrl(Request $request)
    {
        $user = User::find($request->user['id']);
        if (!$user) {
            abort(500, __('The user does not exist'));
        }

        $code = Helper::guid();
        $key = CacheKey::get('TEMP_TOKEN', $code);
        Cache::put($key, $user->id, 60);
        $redirect = '/#/login?verify=' . $code . '&redirect=' . ($request->input('redirect') ? $request->input('redirect') : 'dashboard');
        if (config('v2board.app_url')) {
            $url = config('v2board.app_url') . $redirect;
        } else {
            $url = url($redirect);
        }
        return response([
            'data' => $url
        ]);
    }
}
