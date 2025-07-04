<?php
namespace App\Http\Routes\V1;

use App\Http\Controllers\V1\Admin\NoticeController as AdminNoticeController;
use App\Http\Controllers\V1\Staff\PlanController;
use App\Http\Controllers\V1\Staff\TicketController;
use App\Http\Controllers\V1\Staff\UserController;
use Illuminate\Contracts\Routing\Registrar;

class StaffRoute
{
    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => 'staff',
            'middleware' => 'staff'
        ], function ($router) {
            // Ticket
            $router->get ('/ticket/fetch', [TicketController::class, 'fetch']);
            $router->post('/ticket/reply', [TicketController::class, 'reply']);
            $router->post('/ticket/close', [TicketController::class, 'close']);
            // User
            $router->post('/user/update', [UserController::class, 'update']);
            $router->get ('/user/getUserInfoById', [UserController::class, 'getUserInfoById']);
            $router->post('/user/sendMail', [UserController::class, 'sendMail']);
            $router->post('/user/ban', [UserController::class, 'ban']);
            // Plan
            $router->get ('/plan/fetch', [PlanController::class, 'fetch']);
            // Notice
            $router->get ('/notice/fetch', [AdminNoticeController::class, 'fetch']);
            $router->post('/notice/save', [AdminNoticeController::class, 'save']);
            $router->post('/notice/update', [AdminNoticeController::class, 'update']);
            $router->post('/notice/drop', [AdminNoticeController::class, 'drop']);
        });
    }
}
