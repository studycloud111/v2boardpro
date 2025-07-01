#!/bin/bash

echo "========================================="
echo "开始升级V2BoardPro到Laravel 12"
echo "========================================="

# 检查PHP版本
php_version=$(php -r 'echo PHP_VERSION;')
echo "当前PHP版本: $php_version"

# 检查是否满足Laravel 12的PHP版本要求
php -r 'exit(version_compare(PHP_VERSION, "8.2.0", ">=") ? 0 : 1);'
if [ $? -ne 0 ]; then
  echo "错误: Laravel 12需要PHP 8.2或更高版本"
  exit 1
fi

# 备份重要文件
echo "备份重要文件..."
mkdir -p backup
cp composer.json backup/
cp -r app/Exceptions backup/
cp -r app/Http backup/
cp -r bootstrap backup/

# 安装Composer依赖
echo "更新Composer依赖..."
rm -f composer.lock
composer update --with-all-dependencies

# 清除缓存
echo "清除缓存..."
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear
php artisan optimize:clear

# 迁移数据库
echo "迁移数据库..."
php artisan migrate --force

echo "========================================="
echo "Laravel 12升级完成"
echo "========================================="

echo "请检查以下事项:"
echo "1. 检查routes目录下的路由文件是否正常工作"
echo "2. 检查所有控制器中的方法返回类型是否正确"
echo "3. 检查所有中间件是否正常工作"
echo "4. 测试所有API接口是否正常响应"

exit 0 