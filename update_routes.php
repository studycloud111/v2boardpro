<?php

// 获取所有路由文件
$routeFiles = glob('app/Http/Routes/V1/*.php');
$routeFiles = array_merge($routeFiles, glob('app/Http/Routes/V2/*.php'));

foreach ($routeFiles as $file) {
    echo "Processing {$file}...\n";
    
    // 读取文件内容
    $content = file_get_contents($file);
    
    // 提取命名空间
    preg_match('/namespace\s+([^;]+);/', $content, $matches);
    $namespace = $matches[1];
    
    // 添加控制器导入
    $useStatements = [];
    preg_match_all('/[\'"]([^@\'\"]+)@/', $content, $matches);
    
    foreach ($matches[1] as $controller) {
        $controllerClass = str_replace('\\\\', '\\', $controller);
        $controllerName = substr($controllerClass, strrpos($controllerClass, '\\') + 1);
        $useStatements[] = "use App\\Http\\Controllers\\{$controllerClass};";
    }
    
    $useStatements = array_unique($useStatements);
    $useStatementsStr = implode("\n", $useStatements);
    
    // 在命名空间后添加use语句
    $content = preg_replace(
        '/namespace\s+([^;]+);/',
        "namespace $1;\n\n$useStatementsStr",
        $content
    );
    
    // 替换路由定义
    $content = preg_replace(
        '/[\'"]([^@\'\"]+)@([^\'"]+)[\'"]/',
        '[$1::class, \'$2\']',
        $content
    );
    
    // 写回文件
    file_put_contents($file, $content);
    
    echo "Updated {$file}\n";
}

echo "All route files updated successfully!\n"; 