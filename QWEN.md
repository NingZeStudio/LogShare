# McLogs Next - 项目上下文

## 项目概述

McLogs Next 是一个现代化的 Minecraft 服务器日志分析与分享平台。该项目从前端重构为基于 Vue 3 + TypeScript + Tailwind CSS 的单页应用程序（SPA），提供了更流畅的用户体验和更强大的功能。

### 核心功能
- **日志分享**：通过唯一 URL 轻松分享大型日志文件
- **智能错误分析**：利用先进的分析库自动检测问题并提供解决方案
- **隐私保护**：内置过滤机制，自动隐藏敏感信息
- **现代化界面**：响应式设计，支持深色模式

### 技术栈
- **前端**：Vue 3, Vite, TypeScript, Tailwind CSS
- **后端**：PHP 8.4+
- **数据库**：MongoDB（默认）、Redis、本地文件系统
- **基础设施**：Docker, Docker Compose, Nginx

## 项目结构

```
McLogs_Next/
├── api/                 # API 端点
│   ├── endpoints/       # 各个 API 端点实现
│   ├── frontend/        # 前端处理
│   └── public/          # 公共入口
├── core/                # 核心代码
│   ├── core.php         # 核心加载器
│   ├── config/          # 配置文件
│   └── src/             # 核心源代码
├── docker/              # Docker 配置
│   ├── compose.yaml     # Docker Compose 配置
│   ├── .env             # 环境变量
│   └── mclogs.conf      # Nginx 配置
├── storage/             # 存储目录
├── web/                 # 前端代码
│   ├── src/             # Vue 源码
│   ├── public/          # 静态资源
│   └── package.json     # 前端依赖
├── composer.json        # PHP 依赖
└── README.md            # 项目说明
```

## API 端点

- `/` - 主前端处理器
- `/1/log` - 提交新日志
- `/1/analyse` - 分析日志
- `/1/errors/rate` - 错误率信息
- `/1/limits` - 系统限制信息
- `/1/raw/{id}` - 原始日志内容检索
- `/1/ai-analysis/{id}` - 特定日志的 AI 分析
- `/1/insights/{id}` - 特定日志的洞察

## 配置文件

配置通过 `core/config/` 目录中的 PHP 文件管理：
- `storage.php` - 存储后端配置（MongoDB、Redis、文件系统）
- `urls.php` - 前端和 API 的基础 URL 配置
- `ai.php` - AI 分析设置
- `cache.php` - 缓存配置
- `filter.php` - 日志过滤设置
- `id.php` - ID 生成设置
- `legal.php` - 法律合规设置

## 构建和运行

### 环境要求
- Docker (20.10+)
- Docker Compose (2.0+)
- Node.js (20+, 用于构建前端资源)
- PHP 8.4+ (用于本地开发)

### 部署步骤

1. **安装 PHP 依赖**
   ```bash
   composer install
   ```

2. **构建前端资源**
   ```bash
   cd web
   npm install
   npm run build
   cd ..
   ```

3. **启动服务**
   ```bash
   cd docker
   docker-compose up -d
   ```

### 本地开发

1. **启动后端服务**
   ```bash
   cd docker
   docker-compose up -d
   ```

2. **启动前端开发服务器**
   ```bash
   cd web
   npm install
   npm run dev
   ```

## 开发约定

- PHP 8.4+ 是必需的
- 使用 PSR-4 自动加载，通过 `core.php` 中的自定义加载器实现
- 前端遵循 Vue 3 Composition API 模式与 TypeScript
- 使用 Tailwind CSS 实用优先方法进行样式设计
- 遵循 RESTful API 设计原则
- 采用 Docker 优先的部署方式

## 依赖组件

### PHP 依赖
- `mongodb/mongodb`: 2.1.2
- `aternos/codex-minecraft`: ^5.0.1 (日志分析)
- `aternos/sherlock`: ^1.0.2 (日志分析)
- `aternos/codex-hytale`: ^1.0 (Hytale 日志分析)
- 必需扩展: json, zlib, mbstring

### 前端依赖
- `Vue 3`: ^3.5.24
- `TypeScript`: ~5.9.3
- `Tailwind CSS`: ^3.4.17
- `Axios`: ^1.13.2 (HTTP 客户端)
- `Highlight.js`: ^11.11.1 (语法高亮)
- `Radix-Vue`: ^1.9.17 (UI 组件)

## 存储配置

默认使用 MongoDB 作为存储后端，配置如下：
- 存储时间：90 天
- 最大长度：10MB
- 最大行数：25,000 行

## Docker 配置

Docker Compose 设置了以下服务：
- nginx: Web 服务器，监听端口 9300
- php-fpm: PHP-FPM 服务
- mongo: MongoDB 数据库
- redis: Redis 缓存

## 重要类和接口

- `\Storage\StorageInterface` - 存储接口
- `\Storage\Mongo` - MongoDB 存储实现
- `\Storage\Filesystem` - 文件系统存储实现
- `\Storage\Redis` - Redis 存储实现
- `Log` - 日志处理类
- `ContentParser` - 内容解析器
- `Config` - 配置管理类