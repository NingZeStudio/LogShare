# McLogs Next - Project Context

## Qwen Added Memories
- 必须使用中文回答用户问题

## Project Overview

McLogs Next is a modern web application for sharing and analyzing Minecraft server logs. It addresses the challenge of sharing large log files by providing a platform that offers syntax highlighting and automatic error analysis to help administrators quickly identify server issues.

The project has been refactored from traditional PHP frontend to a modern Single Page Application (SPA) architecture using Vue 3 + TypeScript + Tailwind CSS, providing a smoother user experience.

## Architecture & Technology Stack

### Frontend
- **Framework**: Vue 3, Vite, TypeScript
- **UI Framework**: Tailwind CSS, Shadcn/Vue
- **Build Tool**: Vite
- **Language**: TypeScript

### Backend
- **Language**: PHP 8.4+
- **Architecture**: REST API services
- **Dependencies**: Managed via Composer

### Database & Storage
- **Primary**: MongoDB (default storage)
- **Alternative**: Redis, Local filesystem
- **Cache**: Redis (optional high-speed cache layer)

### Infrastructure
- **Containerization**: Docker, Docker Compose
- **Web Server**: Nginx (reverse proxy and static file serving)
- **Deployment**: Containerized with multi-service orchestration

## Key Features

1. **Log Sharing**: Share large log files via unique URLs without complex upload processes
2. **Smart Analysis**: Integrates aternos/codex library to automatically identify server software, detect errors, and provide solutions
3. **Privacy Protection**: Smart filtering algorithm to hide sensitive information (IP addresses) in logs
4. **Modern UI**: Built with Shadcn/Vue and Tailwind CSS, responsive for mobile and desktop with dark mode support
5. **Multi-backend Storage**: Flexible storage strategies supporting MongoDB (default), Redis, and local filesystem

## Project Structure

```
McLogs_Next/
├── api/                    # API endpoints and frontend routing
│   ├── endpoints/          # Individual API endpoint implementations
│   ├── frontend/           # Frontend-related API handlers
│   └── public/             # Public API entry point (index.php)
├── core/                   # Core application logic
│   ├── core.php            # Core initialization and autoloader
│   ├── config/             # Configuration files
│   └── src/                # Source code classes
├── docker/                 # Docker configuration and setup
│   ├── compose.yaml        # Docker Compose service definitions
│   ├── .env               # Environment variables (empty in repo)
│   └── mclogs.conf        # Nginx configuration
├── web/                    # Frontend application (Vue 3 + TypeScript)
│   ├── src/                # Vue components and application logic
│   ├── public/             # Static assets
│   ├── dist/               # Build output directory
│   └── package.json        # Frontend dependencies and scripts
├── storage/                # Local storage directory
│   └── logs/               # Log storage (when using filesystem backend)
├── composer.json          # PHP dependencies
├── composer.lock          # Locked PHP dependencies
└── README.md              # Project documentation
```

## Dependencies

### PHP Dependencies (composer.json)
- mongodb/mongodb: 2.1.2
- aternos/codex-minecraft: ^5.0.1 (for log analysis)
- aternos/sherlock: ^1.0.2 (for log analysis)
- aternos/codex-hytale: ^1.0 (for Hytale log analysis)
- Required PHP extensions: json, zlib, mbstring

### Frontend Dependencies (package.json)
- Vue 3: ^3.5.24
- TypeScript: ~5.9.3
- Tailwind CSS: ^3.4.17
- Axios: ^1.13.2 (HTTP client)
- Highlight.js: ^11.11.1 (syntax highlighting)
- Radix-Vue: ^1.9.17 (UI components)

## Building and Running

### Prerequisites
- Docker (20.10+)
- Docker Compose (2.0+)
- Node.js (20+, for building frontend resources)
- PHP 8.4+ (for local development)

### Development Setup

1. **Install PHP dependencies**:
   ```bash
   composer install
   ```

2. **Build frontend**:
   ```bash
   cd web
   npm install
   npm run build
   ```

3. **Configure Nginx** (modify `docker/mclogs.conf`):
   - Update `server_name` for frontend domain (e.g., `logs.example.com`)
   - Update `server_name` for API domain (e.g., `api.logs.example.com`)

4. **Start services with Docker**:
   ```bash
   cd docker
   docker-compose up -d
   ```

5. **Set up reverse proxy** on host machine to forward traffic to container port 9300

### Development Mode

For active development:
1. Start backend services using Docker
2. Run frontend development server:
   ```bash
   cd web
   npm run dev
   ```
   Frontend dev server runs on http://localhost:5173

## API Endpoints

The API follows a versioned structure (`/1/`) with the following endpoints:
- `/` - Main frontend handler
- `/1/log` - Submit new log
- `/1/analyse` - Analyze log
- `/1/errors/rate` - Error rate information
- `/1/limits` - System limits information
- `/1/raw/{id}` - Raw log content retrieval
- `/1/ai-analysis/{id}` - AI analysis of specific log
- `/1/insights/{id}` - Insights for specific log

## Configuration

Configuration is managed through PHP files in `core/config/`:
- `storage.php` - Storage backend configuration (MongoDB, Redis, Filesystem)
- `urls.php` - Base URL configuration for frontend and API
- `ai.php` - AI analysis settings
- `cache.php` - Cache configuration
- `filter.php` - Log filtering settings
- `id.php` - ID generation settings
- `legal.php` - Legal compliance settings

## Development Conventions

- PHP 8.4+ is required
- PSR-4 autoloading is implemented via custom autoloader in `core.php`
- Frontend follows Vue 3 Composition API patterns with TypeScript
- Tailwind CSS utility-first approach for styling
- RESTful API design principles
- Docker-first deployment approach

## Deployment Notes

The application requires a specific reverse proxy setup due to the containerized architecture:
- Container Nginx uses `server_name` to distinguish between API and frontend requests
- Host machine must have reverse proxy that forwards domain traffic to container port 9300
- Host header must be preserved for proper routing inside the container
