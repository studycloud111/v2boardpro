<?php
namespace App\Http\Routes\V1;

use Illuminate\Contracts\Routing\Registrar;
use Illuminate\Support\Facades\App;

class ServerRoute
{
    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => 'server'
        ], function ($router) {
            $router->any('/{class}/{action}', function($class, $action) {
                $controllerClass = "\\App\\Http\\Controllers\\V1\\Server\\" . ucfirst($class) . "Controller";
                $controller = App::make($controllerClass);
                return App::call([$controller, $action]);
            });
        });
    }
}
