# McLogs Next API

**现代化 Minecraft 服务器日志分析 API 平台**

[![License](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.4+-777BB4.svg?style=flat-square&logo=php&logoColor=white)](https://www.php.net/)
[![Docker](https://img.shields.io/badge/Docker-Ready-2496ED.svg?style=flat-square&logo=docker&logoColor=white)](https://www.docker.com/)

## 🚀 项目简介

McLogs Next API 是一个现代化的无头应用程序，专为 Minecraft 服务器管理员设计，用于分享、分析和诊断服务器日志。该项目提供了一个完整的 REST API，允许用户提交、分析和检索 Minecraft 服务器日志。

主要特点：
- **简化日志分享**：通过唯一 URL 轻松分享大型日志文件
- **智能错误分析**：利用先进的分析库自动检测问题并提供解决方案
- **隐私保护**：内置过滤机制，自动隐藏敏感信息
- **纯 API 接口**：无头架构，专注于提供强大而可靠的 API 服务

## ✨ 核心功能

- **日志分享**：通过唯一 URL 分享大型日志文件，无需复杂上传流程
- **智能分析**：集成 aternos/codex 库，自动识别服务器软件类型，精准检测错误并提供解决方案
- **隐私保护**：智能过滤算法，自动隐藏日志中的敏感信息（如 IP 地址）
- **API 优先**：纯 API 接口，支持多种客户端集成
- **多后端存储**：灵活的存储策略，支持 MongoDB（默认）、Redis 和本地文件系统
- **错误率统计**：提供服务器错误率统计信息

## 🛠️ 技术栈

| 层级 | 技术 | 描述 |
|------|------|------|
| **后端** | PHP 8.4+ | 提供稳健的 REST API 服务 |
| **数据库** | MongoDB | 高性能日志存储（默认） |
| **缓存** | Redis | 可选的高速缓存层 |
| **基础设施** | Docker, Docker Compose, Nginx | 容器化部署与统一服务管理 |
| **日志分析** | Aternos Codex | Minecraft 日志智能分析引擎 |

## 📦 依赖组件

### PHP 依赖
- `mongodb/mongodb`: 2.1.2
- `aternos/codex-minecraft`: ^5.0.1 (日志分析)
- `aternos/sherlock`: ^1.0.2 (日志分析)
- `aternos/codex-hytale`: ^1.0 (Hytale 日志分析)
- 必需扩展: json, zlib, mbstring, mongodb, redis

## 🚀 快速部署

### 环境要求
- Docker (20.10+)
- Docker Compose (2.0+)

### 部署步骤

1. **克隆项目**
   ```bash
   git clone https://github.com/NingZeStudio/McLogs-Next-API.git
   cd McLogs-Next-API
   ```

2. **安装 PHP 依赖**
   ```bash
   composer install
   ```

3. **启动服务**
   ```bash
   cd docker
   docker compose up -d
   ```

4. **验证部署**
   访问 `http://localhost:9300` (或配置的域名) 来确认 API 正常运行

## 🔧 API 使用指南

### 基础端点

- `GET /` - 获取 API 欢迎信息和可用端点列表
- `POST /1/log` - 提交新的日志数据
- `POST /1/analyse` - 分析日志数据
- `GET /1/errors/rate` - 获取错误率统计信息
- `GET /1/limits` - 获取 API 速率限制
- `GET /1/raw/{id}` - 按 ID 检索原始日志
- `GET /1/ai-analysis/{id}` - 获取特定日志的 AI 分析
- `GET /1/insights/{id}` - 获取特定日志的洞察
- `DELETE /1/delete/{id}` - 按 ID 删除日志

### 示例请求

提交日志:
```bash
curl -X POST http://localhost:9300/1/log \
  -H "Content-Type: application/json" \
  -d '{"content": "你的日志内容"}'
```

获取原始日志:
```bash
curl http://localhost:9300/1/raw/LOG_ID
```

### 配置文件

配置通过 `core/config/` 目录中的 PHP 文件管理：
- `storage.php` - 存储后端配置（MongoDB、Redis、文件系统）
- `ai.php` - AI 分析设置
- `cache.php` - 缓存配置
- `filter.php` - 日志过滤设置
- `id.php` - ID 生成设置
- `legal.php` - 法律合规设置

## 💡 UI 开发选项

此仓库仅包含 API 后端服务。您可以选择以下方式来使用 API：

1. **自主开发前端界面**：使用我们提供的 API 创建您自己的用户界面
2. **使用官方 WebUI**：我们提供了开源的前端界面，可在 [NingZeStudio/McLogs-Next-UI](https://github.com/NingZeStudio/McLogs-Next-UI) 获取

## ⚡ 性能说明

当前 API 版本在性能方面仍有优化空间。我们计划在未来版本中持续改进 API 响应速度和整体性能表现。如果您在使用过程中发现性能问题，请随时在 Issues 中报告。

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
- 遵循 RESTful API 设计原则
- 采用 Docker 优先的部署方式

## 📄 许可证

本项目基于 [MIT License](LICENSE) 开源。

## 📞 支持

如果您遇到任何问题或有改进建议，请：
- 查看 [Issues](https://github.com/NingZeStudio/McLogs-Next-API/issues) 页面
- 提交新的 Issue
- 查阅文档

## 🙏 致谢

- 感谢 [aternos/codex-minecraft](https://github.com/aternosorg/codex) 提供的日志分析能力
- 感谢 Docker、PHP、MongoDB 等优秀开源项目的贡献