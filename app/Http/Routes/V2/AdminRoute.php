<?php
namespace App\Http\Routes\V2;

use App\Http\Controllers\V2\Admin\StatController;
use Illuminate\Contracts\Routing\Registrar;

class AdminRoute
{
    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => config('v2board.secure_path', config('v2board.frontend_admin_path', hash('crc32b', config('app.key')))),
            'middleware' => ['admin', 'log'],
        ], function ($router) {
            // Stat
            $router->get ('/stat/override', [StatController::class, 'override']);
            $router->get ('/stat/record', [StatController::class, 'record']);
            $router->get ('/stat/ranking', [StatController::class, 'ranking']);
        });
    }
}
