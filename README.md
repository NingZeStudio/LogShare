<div align="center">

  # McLogs Next

  **现代化 Minecraft 服务器日志分析与分享平台**

  [![License](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)](LICENSE)
  [![PHP](https://img.shields.io/badge/PHP-8.1+-777BB4.svg?style=flat-square&logo=php&logoColor=white)](https://www.php.net/)
  [![Vue](https://img.shields.io/badge/Vue.js-3.x-4FC08D.svg?style=flat-square&logo=vue.js&logoColor=white)](https://vuejs.org/)
  [![Docker](https://img.shields.io/badge/Docker-Ready-2496ED.svg?style=flat-square&logo=docker&logoColor=white)](https://www.docker.com/)

</div>

---

## 项目简介

McLogs Next 是一个用于粘贴、分享和分析 Minecraft 服务器日志的现代化 Web 应用程序。它解决了分享大体积日志文件的难题，并提供语法高亮和自动错误分析功能，帮助管理员快速定位服务器问题。

本项目已从传统的 PHP 前端重构为基于 Vue 3 + TypeScript + Tailwind CSS 的现代化单页应用（SPA），提供更流畅的用户体验。

## 功能特性

*   日志分享：通过唯一 URL 轻松分享大型日志文件，无需复杂的上传流程。
*   智能分析：集成 aternos/codex 库，自动识别服务器软件，精准检测错误并提供解决方案。
*   隐私保护：智能过滤算法，自动隐藏日志中的敏感信息（如 IP 地址）。
*   现代化 UI：基于 Shadcn/Vue 和 Tailwind CSS 构建，完美适配移动端和桌面端，支持深色模式。
*   多后端存储：灵活的存储策略，支持 MongoDB（默认）、Redis 和本地文件系统。

## 技术栈

| 模块 | 技术 | 说明 |
| :--- | :--- | :--- |
| **Frontend** | Vue 3, Vite, TypeScript | 现代化 SPA 架构 |
| **UI Framework** | Tailwind CSS, Shadcn/Vue | 极简且高度可定制的 UI 组件 |
| **Backend** | PHP 8.1+ | 提供稳健的 REST API 服务 |
| **Database** | MongoDB | 高性能日志存储（默认） |
| **Cache** | Redis | 可选的高速缓存层 |
| **Infrastructure** | Docker, Nginx | 容器化部署与统一流量分发 |

## 界面预览

![9b9490de733e28a7.png](https://cdn.zeinklab.com/myfile/images/9b9490de733e28a7.png)

## 快速部署

本项目包含前后端两个服务，共用同一端口，因此反向代理配置至关重要。请严格按照以下步骤操作。

### 1. 环境准备

确保您的服务器已安装以下环境：

1.  Docker (20.10+)
2.  Docker Compose (2.0+)
3.  Node.js (20+，仅用于构建前端资源)

### 2. 安装依赖和构建静态资源

在启动 Docker 容器前，需要安装PHP依赖：

```bash
composer install
```

编译前端代码：

```bash
# 进入前端目录
cd web

# 安装依赖
npm install

# 执行构建
npm run build
```

构建产物将生成在 web/dist 目录下，该目录将自动映射到 Nginx 容器中。

### 3. 修改 Nginx 配置

由于容器内的 Nginx 使用 server_name 区分 API 和前端请求，您必须修改配置文件以匹配您的实际域名。

1.  编辑配置文件：`docker/mclogs.conf`
2.  修改两个 server 块中的 server_name：

```nginx
# 前端服务块
server {
    ...
    server_name logs.example.com; # 修改为您的前端域名
    root /web/mclogs/web/dist;
    ...
}

# API 服务块
server {
    ...
    server_name api.logs.example.com; # 修改为您的 API 域名
    root /web/mclogs/api/public;
    ...
}
```

### 4. 启动服务

使用 Docker Compose 启动所有服务（Nginx, PHP-FPM, MongoDB）：

```bash
cd docker
docker-compose up -d
```

### 5. 配置反向代理（强制要求）

Docker 容器将 80 端口映射到了宿主机的 127.0.0.1:9300。由于容器内部依赖 Host 请求头来区分是访问前端还是 API，您无法直接通过 IP 访问。

您必须在宿主机搭建一层反向代理（如 Nginx、Caddy 或 Apache），将域名流量转发到本地的 9300 端口，并传递 Host 头。

#### 宿主机 Nginx 配置示例

```nginx
# 前端反向代理
server {
    listen 80;
    server_name logs.example.com; # 必须与步骤 3 中的前端域名一致

    location / {
        proxy_pass http://127.0.0.1:9300;
        
        # 必须传递 Host 头，否则容器内 Nginx 无法正确路由请求
        proxy_set_header Host $host; 
        
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    }
}

# API 反向代理
server {
    listen 80;
    server_name api.logs.example.com; # 必须与步骤 3 中的 API 域名一致

    location / {
        proxy_pass http://127.0.0.1:9300;
        
        # 必须传递 Host 头
        proxy_set_header Host $host;
        
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    }
}
```

## 开发指南

如果您希望参与开发或进行本地调试：

1.  启动后端服务：使用 Docker 启动数据库和 PHP 环境。
2.  启动前端热更新服务器：

```bash
cd web
npm run dev
```

前端开发服默认运行在 http://localhost:5173。

## 许可证

本项目基于 [MIT License](LICENSE) 开源
