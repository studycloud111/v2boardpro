<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class UserRedeemGiftCard extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'promo_code' => 'required_without:giftcard',
            'giftcard' => 'required_without:promo_code'
        ];
    }

    public function messages()
    {
        return [
            'promo_code.required_without' => __('兑换码不能为空'),
            'giftcard.required_without' => __('兑换码不能为空')
        ];
    }
}
