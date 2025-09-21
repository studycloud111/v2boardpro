<?php

namespace Tests\Feature;

use App\Models\Giftcard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GiftcardInfoTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_get_giftcard_info()
    {
        // 创建测试用户
        $user = User::factory()->create();

        // 创建一个测试礼品卡
        $giftcard = Giftcard::create([
            'name' => '测试礼品卡',
            'code' => 'TEST123456789',
            'type' => 1, // 金额类型
            'value' => 1000, // 10.00 元
            'started_at' => time() - 3600, // 1小时前开始
            'ended_at' => time() + 86400, // 24小时后过期
            'limit_use' => 5
        ]);

        // 测试用户接口
        $response = $this->actingAs($user)->get('/api/v1/user/giftcard/info?code=' . $giftcard->code);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'name' => '测试礼品卡',
                    'type' => '金额',
                    'type_id' => 1,
                    'value' => 1000,
                    'formatted_value' => '10 ¥',
                    'remaining_uses' => 5,
                    'is_valid' => true,
                    'message' => ''
                ]
            ]);
    }

    public function test_user_gets_error_for_nonexistent_giftcard()
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user)->get('/api/v1/user/giftcard/info?code=NONEXISTENT');

        $response->assertStatus(404);
    }

    public function test_user_gets_error_for_missing_code()
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user)->get('/api/v1/user/giftcard/info');

        $response->assertStatus(400);
    }

    public function test_expired_giftcard_shows_invalid_status()
    {
        $user = User::factory()->create();

        // 创建一个已过期的礼品卡
        $giftcard = Giftcard::create([
            'name' => '过期礼品卡',
            'code' => 'EXPIRED123456',
            'type' => 2, // 时长类型
            'value' => 30, // 30天
            'started_at' => time() - 86400 * 2, // 2天前开始
            'ended_at' => time() - 86400, // 1天前过期
            'limit_use' => null // 不限制使用次数
        ]);

        $response = $this->actingAs($user)->get('/api/v1/user/giftcard/info?code=' . $giftcard->code);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'name' => '过期礼品卡',
                    'type' => '时长',
                    'type_id' => 2,
                    'value' => 30,
                    'formatted_value' => '30 天',
                    'remaining_uses' => null,
                    'is_valid' => false
                ]
            ])
            ->assertJsonFragment([
                'message' => 'The gift card has expired'
            ]);
    }

    public function test_different_giftcard_types_formatting()
    {
        $user = User::factory()->create();

        // 测试不同类型的礼品卡格式化
        $testCases = [
            [
                'type' => 1, // 金额
                'value' => 500,
                'expected_formatted' => '5 ¥'
            ],
            [
                'type' => 2, // 时长
                'value' => 15,
                'expected_formatted' => '15 天'
            ],
            [
                'type' => 3, // 流量
                'value' => 100,
                'expected_formatted' => '100 GB'
            ],
            [
                'type' => 4, // 重置
                'value' => 1,
                'expected_formatted' => '重置套餐'
            ],
            [
                'type' => 5, // 套餐
                'value' => 7,
                'expected_formatted' => '7 天套餐'
            ]
        ];

        foreach ($testCases as $index => $testCase) {
            $giftcard = Giftcard::create([
                'name' => '测试礼品卡 ' . $index,
                'code' => 'TYPE' . $testCase['type'] . 'TEST' . $index,
                'type' => $testCase['type'],
                'value' => $testCase['value'],
                'started_at' => time() - 3600,
                'ended_at' => time() + 86400,
                'limit_use' => null
            ]);

            $response = $this->actingAs($user)->get('/api/v1/user/giftcard/info?code=' . $giftcard->code);

            $response->assertStatus(200)
                ->assertJsonFragment([
                    'formatted_value' => $testCase['expected_formatted']
                ]);
        }
    }
}
