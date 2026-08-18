# php-fpm 两阶段构建：编译 SpinYarn C ABI 库 + PHP 扩展，装入运行时镜像
# 阶段 1：编译 libspinyarn_capi.so
FROM rust:1 AS spinyarn-capi
RUN git clone --depth 1 --branch v1.0.0-pre.1 https://github.com/NingZeStudio/SpinYarn /spinyarn
WORKDIR /spinyarn
RUN cargo build --release --workspace

# 阶段 2：最终 php-fpm（装 mongodb/redis + 编译并启用 spinyarn 扩展）
FROM php:8.5-fpm
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions mongodb redis

# phpize 编译 spinyarn 扩展所需的构建工具
RUN apt-get update && apt-get install -y --no-install-recommends autoconf build-essential && rm -rf /var/lib/apt/lists/*

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

COPY mclogs.ini /usr/local/etc/php/conf.d/mclogs.ini
