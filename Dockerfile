# 使用官方 PHP + Apache 映像
FROM php:8.1-apache

# 安裝必要 PHP extension
RUN apt-get update \
    && apt-get install -y libzip-dev zip \
    && docker-php-ext-install pdo pdo_mysql zip \
    && rm -rf /var/lib/apt/lists/*

# 設定工作目錄
WORKDIR /var/www/html

# 複製 Composer 定義
COPY composer.json composer.lock ./
# 安裝 Composer 依賴
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/bin --filename=composer \
    && composer install --no-dev --optimize-autoloader

# 複製原始程式碼
COPY src/ ./

# 調整權限（若需寫入）
RUN chown -R www-data:www-data /var/www/html

# Expose HTTP
EXPOSE 80

# 預設啟動 Apache
CMD ["apache2-foreground"]