# Hyperf 常驻进程镜像：Swoole 6.2 + SpinYarn 扩展 + pdo_mysql/redis
# 阶段 1：编译 SpinYarn C ABI 库
FROM rust:1 AS spinyarn-capi
RUN git clone --depth 1 --branch v1.0.0-pre.1 https://github.com/NingZeStudio/SpinYarn /spinyarn
WORKDIR /spinyarn
RUN cargo build --release --workspace

# 阶段 2：最终镜像（php:8.5-cli，Hyperf 常驻进程）
FROM php:8.5-cli

# 扩展安装器：swoole / pdo_mysql / redis 一键安装
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_mysql redis

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

# 映射目录：首次反混淆时由 auto_download 按需补全
RUN mkdir -p /opt/spinyarn/mappings
ENV SPINYARN_MAPPINGS_DIR=/opt/spinyarn/mappings

WORKDIR /app

# 启动前建表 + 构建 RAG 索引（幂等），随后常驻
CMD ["sh", "-c", "php bin/hyperf.php migrate 2>/dev/null || true; php bin/hyperf.php rag:build; php bin/hyperf.php start"]
