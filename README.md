# McLogs Next

**现代化 Minecraft 服务器日志分析与分享平台**

[![License](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.4+-777BB4.svg?style=flat-square&logo=php&logoColor=white)](https://www.php.net/)
[![Vue](https://img.shields.io/badge/Vue.js-3.x-4FC08D.svg?style=flat-square&logo=vue.js&logoColor=white)](https://vuejs.org/)
[![Docker](https://img.shields.io/badge/Docker-Ready-2496ED.svg?style=flat-square&logo=docker&logoColor=white)](https://www.docker.com/)

## 🚀 项目简介

McLogs Next 是一个现代化的 Web 应用程序，专为 Minecraft 服务器管理员设计，用于分享、分析和诊断服务器日志。该项目从传统的 PHP 前端重构为基于 Vue 3 + TypeScript + Tailwind CSS 的单页应用（SPA），提供了更流畅的用户体验和更强大的功能。

主要特点：
- **简化日志分享**：通过唯一 URL 轻松分享大型日志文件
- **智能错误分析**：利用先进的分析库自动检测问题并提供解决方案
- **隐私保护**：内置过滤机制，自动隐藏敏感信息
- **现代化界面**：响应式设计，支持深色模式

## ✨ 核心功能

- **日志分享**：通过唯一 URL 分享大型日志文件，无需复杂上传流程
- **智能分析**：集成 aternos/codex 库，自动识别服务器软件类型，精准检测错误并提供解决方案
- **隐私保护**：智能过滤算法，自动隐藏日志中的敏感信息（如 IP 地址）
- **现代化 UI**：基于 Shadcn/Vue 和 Tailwind CSS 构建，完美适配移动端和桌面端，支持深色模式
- **多后端存储**：灵活的存储策略，支持 MongoDB（默认）、Redis 和本地文件系统
- **语法高亮**：为日志文件提供专业的语法高亮显示

## 🛠️ 技术栈

| 层级 | 技术 | 描述 |
|------|------|------|
| **前端** | Vue 3, Vite, TypeScript | 现代化 SPA 架构 |
| **UI 框架** | Tailwind CSS, Shadcn/Vue | 极简且高度可定制的 UI 组件 |
| **构建工具** | Vite | 快速的构建和开发体验 |
| **后端** | PHP 8.4+ | 提供稳健的 REST API 服务 |
| **数据库** | MongoDB | 高性能日志存储（默认） |
| **缓存** | Redis | 可选的高速缓存层 |
| **基础设施** | Docker, Docker Compose, Nginx | 容器化部署与统一服务管理 |

## 📦 依赖组件

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

## 🚀 快速部署

### 环境要求
- Docker (20.10+)
- Docker Compose (2.0+)
- Node.js (20+, 用于构建前端资源)
- PHP 8.4+ (用于本地开发)

### 部署步骤

1. **克隆项目**
   ```bash
   git clone https://github.com/your-repo/McLogs_Next.git
   cd McLogs_Next
   ```

2. **安装 PHP 依赖**
   ```bash
   composer install
   ```

3. **构建前端资源**
   ```bash
   cd web
   npm install
   npm run build
   cd ..
   ```

4. **配置环境变量**
   ```bash
   cd docker
   cp .env.example .env
   # 编辑 .env 文件以配置您的设置
   ```

5. **修改 Nginx 配置**
   
   编辑 `docker/mclogs.conf` 文件，将域名替换为您的实际域名：
   
   ```nginx
   # 前端服务
   server {
       ...
       server_name logs.yourdomain.com;  # 替换为您的前端域名
       ...
   }
   
   # API 服务
   server {
       ...
       server_name api.logs.yourdomain.com;  # 替换为您的 API 域名
       ...
   }
   ```

6. **启动服务**
   ```bash
   docker-compose up -d
   ```

7. **配置反向代理**
   
   由于容器内的 Nginx 使用 server_name 区分 API 和前端请求，您需要在宿主机上配置反向代理，将域名流量转发到容器的 9300 端口：
   
   ```nginx
   # 前端反向代理
   server {
       listen 80;
       server_name logs.yourdomain.com;
       
       location / {
           proxy_pass http://127.0.0.1:9300;
           proxy_set_header Host $host;
           proxy_set_header X-Real-IP $remote_addr;
           proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
       }
   }
   
   # API 反向代理
   server {
       listen 80;
       server_name api.logs.yourdomain.com;
       
       location / {
           proxy_pass http://127.0.0.1:9300;
           proxy_set_header Host $host;
           proxy_set_header X-Real-IP $remote_addr;
           proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
       }
   }
   ```

## 🔧 开发指南

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
   
   前端开发服务器将在 http://localhost:5173 上运行。

3. **API 端点**
   
   API 遵循版本化结构 (`/1/`)：
   - `/` - 主前端处理器
   - `/1/log` - 提交新日志
   - `/1/analyse` - 分析日志
   - `/1/errors/rate` - 错误率信息
   - `/1/limits` - 系统限制信息
   - `/1/raw/{id}` - 原始日志内容检索
   - `/1/ai-analysis/{id}` - 特定日志的 AI 分析
   - `/1/insights/{id}` - 特定日志的洞察

### 配置文件

配置通过 `core/config/` 目录中的 PHP 文件管理：
- `storage.php` - 存储后端配置（MongoDB、Redis、文件系统）
- `urls.php` - 前端和 API 的基础 URL 配置
- `ai.php` - AI 分析设置
- `cache.php` - 缓存配置
- `filter.php` - 日志过滤设置
- `id.php` - ID 生成设置
- `legal.php` - 法律合规设置

## 🤝 贡献指南

我们欢迎社区贡献！以下是参与项目的方式：

1. Fork 项目
2. 创建功能分支 (`git checkout -b feature/AmazingFeature`)
3. 提交更改 (`git commit -m 'Add some AmazingFeature'`)
4. 推送到分支 (`git push origin feature/AmazingFeature`)
5. 创建 Pull Request

### 开发约定

- PHP 8.4+ 是必需的
- 使用 PSR-4 自动加载，通过 `core.php` 中的自定义加载器实现
- 前端遵循 Vue 3 Composition API 模式与 TypeScript
- 使用 Tailwind CSS 实用优先方法进行样式设计
- 遵循 RESTful API 设计原则
- 采用 Docker 优先的部署方式

## 📄 许可证

本项目基于 [MIT License](LICENSE) 开源。

## 📞 支持

如果您遇到任何问题或有改进建议，请：
- 查看 [Issues](https://github.com/your-repo/McLogs_Next/issues) 页面
- 提交新的 Issue
- 查阅文档

## 🙏 致谢

- 感谢 [aternos/codex-minecraft](https://github.com/aternosorg/codex) 提供的日志分析能力
- 感谢 Vue.js、Tailwind CSS、Docker 等优秀开源项目的贡献