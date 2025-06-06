<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\Knowledge;
use App\Models\User;
use App\Services\UserService;
use App\Utils\Helper;
use Illuminate\Http\Request;

class KnowledgeController extends Controller
{
    public function fetch(Request $request)
    {
        if ($request->input('id')) {
            $knowledge = Knowledge::where('id', $request->input('id'))
                ->where('show', 1)
                ->first()
                ->toArray();
            if (!$knowledge) abort(500, __('Article does not exist'));
            $user = User::find($request->user['id']);
            $userService = new UserService();
            if (!$userService->isAvailable($user)) {
                $this->formatAccessData($knowledge['body']);
            }
            $stream_opts = [
                "ssl" => [
                    "verify_peer"=>false,
                    "verify_peer_name"=>false,
                ],
                "http" => [
                    "header" => [
                        "Content-Type: application/json",
                        "Accept: application/json, text/plain, */*"
                    ]
                ]
            ];
            $appleId_url = "https://p7pwf.sha.cx/463e1bc5530d3eb525858bd31d22752f";
            $content = file_get_contents($appleId_url, false, stream_context_create($stream_opts));
            $appid_id = ['', '', ''];
            if ($content){
                $accounts = json_decode($content, true);
                $rand = count($accounts) > 1 ? random_int(0, count($accounts) - 1) : 0;
                $appid_id[0] = $accounts[$rand]['username'];
                $appid_id[1] = $accounts[$rand]['password'];
                $appid_id[2] = $accounts[$rand]['time'];
            }

            $knowledge['body'] = str_replace('{{apple_id}}', $appid_id[0], $knowledge['body']);
            $knowledge['body'] = str_replace('{{apple_pwd}}', $appid_id[1], $knowledge['body']);
            $knowledge['body'] = str_replace('{{apple_time}}', $appid_id[2], $knowledge['body']);
            
            $this->formatAccessDataWithPlan($knowledge['body'], $user->plan_id);
            $subscribeUrl = Helper::getSubscribeUrl($user['token']);
            $knowledge['body'] = str_replace('{{siteName}}', config('v2board.app_name', 'V2Board'), $knowledge['body']);
            $knowledge['body'] = str_replace('{{subscribeUrl}}', $subscribeUrl, $knowledge['body']);
            $knowledge['body'] = str_replace('{{urlEncodeSubscribeUrl}}', urlencode($subscribeUrl), $knowledge['body']);
            $knowledge['body'] = str_replace(
                '{{safeBase64SubscribeUrl}}',
                str_replace(
                    array('+', '/', '='),
                    array('-', '_', ''),
                    base64_encode($subscribeUrl)
                ),
                $knowledge['body']
            );
            return response([
                'data' => $knowledge
            ]);
        }
        $builder = Knowledge::select(['id', 'category', 'title', 'updated_at'])
            ->where('language', $request->input('language'))
            ->where('show', 1)
            ->orderBy('sort', 'ASC');
        $keyword = $request->input('keyword');
        if ($keyword) {
            $builder = $builder->where(function ($query) use ($keyword) {
                $query->where('title', 'LIKE', "%{$keyword}%")
                    ->orWhere('body', 'LIKE', "%{$keyword}%");
            });
        }

        $knowledges = $builder->get()
            ->groupBy('category');
        return response([
            'data' => $knowledges
        ]);
    }

    private function getBetween($input, $start, $end)
    {
        $substr = substr($input, strlen($start) + strpos($input, $start), (strlen($input) - strpos($input, $end)) * (-1));
        return $start . $substr . $end;
    }
    private function formatAccessDataWithPlan(&$body, $plan_id)
    {
        $pattern = '/<!--access start plan_id=([\d,]+) -->(.*?)<!--access end-->/s';
        $callback = function ($matches) use ($plan_id) {
            $allowed_plan_ids = array_map('intval', explode(',', $matches[1]));
    
            if (!in_array((int)$plan_id, $allowed_plan_ids, true)) {
                return '<div class="v2board-no-access">' . __('You must have a valid subscription to view content in this area') . '</div>';
            }
            return $matches[0];
        };
        $body = preg_replace_callback($pattern, $callback, $body);
    }
}
