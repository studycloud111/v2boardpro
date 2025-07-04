#!/bin/bash

# 升级到Laravel 12的脚本

echo "开始升级到Laravel 12..."

# 更新依赖
echo "更新Composer依赖..."
composer update

# 清除缓存
echo "清除缓存..."
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear

# 优化应用
echo "优化应用..."
php artisan optimize

echo "升级完成！现在您的应用已经运行在Laravel 12上了。" 