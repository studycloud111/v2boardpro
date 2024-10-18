<?php


namespace App\Http\Controllers\V1\Moetor;

use App\Http\Controllers\Controller;

class MoetorController extends Controller
{

    public function get()
    {
        $res['data'] = config('moetor');
        return response()->json($res);
    }
    
}