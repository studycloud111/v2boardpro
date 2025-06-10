<?php
    $api = "https://api.paytaro.com";
    function fetchWithGzip($url) {
        $options = [
            "http" => [
                "method" => "GET",
                "header" => "Accept-Encoding: gzip\r\n"
            ]
        ];

        $context = stream_context_create($options);
        $response = file_get_contents($url, false, $context);

        // Check if the response is gzipped and decode it
        if ($response !== false && isset($http_response_header)) {
            foreach ($http_response_header as $header) {
                if (stripos($header, "Content-Encoding: gzip") !== false) {
                    return gzdecode($response);
                }
            }
        }

        return $response; // Return uncompressed response if it's not gzipped
    }
    function request($url, $method, $data = [], $headers = []) {
        $responseHeaders = [];         // 用来存储响应头

        $headerCallback = function ($ch, $headerLine) use (&$responseHeaders) {
            // 正确返回长度
            $lineLength = strlen($headerLine);
        
            // 处理头部内容，忽略空行和无效数据
            if (trim($headerLine) === '' || strpos($headerLine, ':') === false) {
                return $lineLength;
            }
        
            // 分割头部为键值对
            list($key, $value) = explode(':', $headerLine, 2);
            $responseHeaders[trim(strtolower($key))] = trim($value);
        
            return $lineLength; // 确保返回值是原始行的长度
        };
        
        // 初始化 cURL
        $ch = curl_init($url);
        
        // 设置 cURL 参数
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);  // 返回响应正文
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, $headerCallback);  // 注册头部回调函数
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, file_get_contents('php://input'));
        }
        // 执行请求并获取正文
        $responseBody = curl_exec($ch);
        if (!$responseBody) {
            echo curl_error($ch);
            exit;
        }
        
        return [
            'data' => $responseBody,
            'headers' => $responseHeaders
        ];
        
        // 关闭 cURL 资源
        curl_close($ch);
    }
    if (isset($_REQUEST['s'])) {
        $response = request("{$api}{$_REQUEST['s']}", $_SERVER['REQUEST_METHOD'], file_get_contents('php://input'), [
            'content-type: application/json',
        ]);

        if (isset($response['headers']['location'])) {
            header("location:" . $response['headers']['location']);
            exit;
        }
        
        header('content-type: application/json');
        http_response_code(200);
        echo $response['data'];
        exit;
    }
    if (isset($_REQUEST['pid'])) {
        $response = request("{$api}/submit.php?" . http_build_query($_REQUEST), 'GET');
        if (isset($response['headers']['location'])) {
            $parsed = parse_url($response['headers']['location']);
            $uuid = str_replace('/', '', $parsed['fragment']);
            header("location:submit.php#/{$uuid}");
            exit;
        }
        $data = json_decode($response['data'], true);
        if (isset($data['message'])) {
            echo $data['message'];
            exit;
        }
        exit;
    }
    $js = fetchWithGzip("https://cashier-assets.pages.dev/assets/index.js");
    $css = fetchWithGzip("https://cashier-assets.pages.dev/assets/index.css");
    header("Cache-Control: max-age=3600, must-revalidate"); // 缓存 1 小时
    header("Expires: " . gmdate("D, d M Y H:i:s", time() + 3600) . " GMT");
?>

<!DOCTYPE html>
<html dir="ltr" lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta
      name="viewport"
      content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=0"
    />
    <script>
        window.api = window.location.origin + window.location.pathname + "?s="
        document.title = "Paytaro"
    </script>
    <script type="module">
        <?php echo $js; ?>
    </script>
    <style>
        <?php echo $css; ?>
    </style>
  </head>
  <body>
    <div id="app"></div>
  </body>
  <script type="text/javascript">
    (function (c, l, a, r, i, t, y) {
      c[a] =
        c[a] ||
        function () {
          (c[a].q = c[a].q || []).push(arguments);
        };
      t = l.createElement(r);
      t.async = 1;
      t.src = 'https://www.clarity.ms/tag/' + i;
      y = l.getElementsByTagName(r)[0];
      y.parentNode.insertBefore(t, y);
    })(window, document, 'clarity', 'script', 'l7es36tc4f');
  </script>
</html>
