# LogShare-v1

## 项目简介

LogShare-v1 是一款专为 Minecraft 和 Hytale 玩家、服务器管理员及开发者打造的日志分析与快速诊断工具。无论你是正在排查服务器崩溃原因的服主，还是想要了解游戏客户端报错原因的普通玩家，LogShare-v1 都能帮助你快速定位问题、获得解决方案，并以简洁易用的方式分享日志给他人。

本项目基于全球知名的 Aternos 日志分析工具链（`aternos/codex-minecraft`、`aternos/sherlock`、`aternos/codex-hytale`）构建，旨在为中文用户提供一个**本土化、高可用性、稳定可靠**的 mclo.gs 替代方案。我们深知，对于国内用户而言，访问国际服务时常面临网络延迟、语言障碍、使用门槛高等问题。LogShare-v1 正是为了解决这些痛点而生——我们提供全中文界面与文档、更贴近国内使用习惯的交互设计、以及更稳定快速的本地化服务体验。

LogShare-v1 采用纯 API 后端架构（无头应用），专注于提供强大、灵活、易集成的日志分析服务。这意味着你可以自由选择任何前端框架来构建用户界面，或者直接将 API 集成到你现有的工具链、Discord 机器人、QQ 机器人、自动化运维系统中。无论是个人使用、服务器社区部署，还是商业项目集成，LogShare-v1 都能提供坚实的技术支撑。

## 技术栈

| 层级 | 技术选型 | 说明 |
|------|----------|------|
| **后端语言** | PHP 8.4+ | 高性能、类型安全的现代 PHP |
| **日志分析** | Aternos Codex | 全球领先的 Minecraft 日志分析引擎 |
| **数据库** | MongoDB | 高性能文档数据库，适合日志存储 |
| **缓存** | Redis | 可选的高速缓存层 |
| **基础设施** | Docker & Docker Compose | 容器化部署，统一环境管理 |
| **Web 服务器** | Nginx | 高性能反向代理与静态资源服务 |

## 依赖组件

| 类型 | 名称/包名 | 版本/说明 |
|------|-----------|-----------|
| **PHP 扩展** | `mongodb` | MongoDB 数据库驱动 |
| | `redis` | Redis 缓存支持（可选） |
| | `json` | JSON 数据处理 |
| | `zlib` | 压缩/解压支持 |
| | `mbstring` | 多字节字符串处理 |
| **Composer 依赖** | `mongodb/mongodb` | 2.1.2 |
| | `aternos/codex-minecraft` | ^5.0.1 |
| | `aternos/sherlock` | ^1.0.3 |
| | `aternos/codex-hytale` | ^2.0.0 |


## 快速开始

### 环境要求
- Docker 20.10+
- Docker Compose 2.0+
- 至少 512MB 可用内存
- 至少 1GB 可用磁盘空间

### 部署步骤

```bash
# 克隆项目
git clone https://cnb.cool/MornZe-Dev/LogShare-v1.git
cd LogShare-v1

# 启动 Docker 服务
cd docker
docker compose up -d
```

#### 验证部署
访问 `http://localhost:9300` 查看 API 欢迎页面

#### （可选）配置前端界面
如需图形化界面，请参考 [LogShareUI-v1](https://cnb.cool/MornZe-Dev/LogShareUI-v1) 项目

### 批量测试

我们提供了完整的 API 测试脚本，包含 19 项测试用例：

```bash
# 运行测试（需要 jq）
./test-api.sh http://localhost:9300
```

测试覆盖：
- 基础功能测试（首页、限制、过滤器）
- 日志提交测试（简单日志、元数据）
- 隐私过滤测试（IPv4、IPv6、用户名、Token）
- 日志分析测试
- 日志读取测试
- 错误处理测试
- 特殊字符测试（中文、长日志）


## 配置说明

配置文件位于 `core/config/` 目录：

| 文件 | 用途 |
|------|------|
| `storage.php` | 存储后端配置（MongoDB/Redis/Filesystem） |
| `cache.php` | 缓存配置 |
| `filter.php` | 日志过滤规则 |
| `id.php` | ID 生成策略 |
| `ai.php` | AI 分析配置（如启用） |
| `legal.php` | 法律合规设置 |


## 前端集成

LogShare-v1 是纯 API 后端服务，你可以：

1. **使用官方前端**：[LogShareUI-v1](https://cnb.cool/MornZe-Dev/LogShareUI-v1)
2. **自建前端**：基于 API 开发自定义界面
3. **集成到现有系统**：Discord 机器人、QQ 机器人、自动化运维平台

## 未来计划

- [ ] RoadRunner & Hyperf 重构（基于 PHP 8.5）
- [ ] 性能优化与缓存策略改进
- [ ] 更多日志格式支持
- [ ] AI 辅助分析集成
- [ ] 统计面板与可视化


## 许可证

本项目采用 [MIT License](LICENSE) 开源。

**LogShare-v1 —— 让日志分析更简单，让问题诊断更高效。**