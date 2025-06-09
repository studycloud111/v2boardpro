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
            'promo_code' => 'required'
        ];
    }

    public function messages()
    {
        return [
            'promo_code.required' => __('兑换码不能为空')
        ];
    }
}
