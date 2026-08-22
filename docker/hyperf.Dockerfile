# Hyperf 常驻进程镜像：Swoole 6.2 + SpinYarn 扩展 + pdo_mysql/redis
# 阶段 1：编译 SpinYarn C ABI 库
FROM rust:1 AS spinyarn-capi
RUN git clone --depth 1 --branch v1.0.0 https://github.com/NingZeStudio/SpinYarn /spinyarn
WORKDIR /spinyarn
RUN cargo build --release --workspace

# 阶段 2：最终镜像（php:8.5-cli，Hyperf 常驻进程）
FROM php:8.5-cli

# Composer（安装项目依赖用）
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# 扩展安装器：pdo_mysql / redis / zip（zip 供 composer 解压 dist 包）
# pcntl / posix / sockets 为 Hyperf 注解扫描与 Swoole 所需
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_mysql redis zip pcntl posix sockets mbstring

# 编译 Swoole 6.2（PHP 8.5 需 Swoole 6.2+）
RUN apt-get update && apt-get install -y --no-install-recommends \
        autoconf build-essential libcurl4-openssl-dev libssl-dev zlib1g-dev libc-ares-dev libbrotli-dev \
    && pecl install swoole \
    && docker-php-ext-enable swoole \
    && rm -rf /var/lib/apt/lists/*

# 编译 SpinYarn PHP 扩展（C ABI 来自阶段 1）
COPY --from=spinyarn-capi /spinyarn /spinyarn
RUN cd /spinyarn/crates/php \
    && phpize \
    && ./configure --enable-spinyarn --with-spinyarn-libdir=/spinyarn/target/release \
    && make \
    && make install \
    && docker-php-ext-enable spinyarn

# C ABI 共享库 + 动态链接器路径
COPY --from=spinyarn-capi /spinyarn/target/release/libspinyarn_capi.so /usr/local/lib/libspinyarn_capi.so
RUN echo '/usr/local/lib' > /etc/ld.so.conf.d/spinyarn.conf && ldconfig

# 项目依赖 + 代码（代码打进镜像，避免依赖运行时挂载 vendor）
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-progress --no-interaction
COPY . .

# 映射目录：/app/mappings（compose 以 bind mount 挂载宿主机 ./mappings，映射表由
# 下载脚本 scripts/download_mappings.sh + download_vanilla_mappings.py 预先生成）

# 启动：构建 RAG 索引（幂等），随后常驻
CMD ["sh", "-c", "php bin/hyperf.php rag:build && php bin/hyperf.php start"]
