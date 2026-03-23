# mclogs 代码迁移总结

## 迁移概述

成功将新版 mclogs (aternosorg/mclogs) 的核心功能迁移到 LogShare-v1，同时保持旧版 API 格式和部署方式不变。

## 迁移内容

### 1. 新增核心类 (`core/src/`)

#### Filter 系统 (新版)
- `Filter/Filter.php` - 过滤器基类
- `Filter/FilterType.php` - 过滤器类型枚举
- `Filter/TrimFilter.php` - 修剪过滤器
- `Filter/LimitBytesFilter.php` - 字节限制过滤器
- `Filter/LimitLinesFilter.php` - 行数限制过滤器
- `Filter/IPv4Filter.php` - IPv4 地址过滤
- `Filter/IPv6Filter.php` - IPv6 地址过滤
- `Filter/UsernameFilter.php` - 用户名过滤
- `Filter/AccessTokenFilter.php` - 访问令牌过滤
- `Filter/Pattern/Pattern.php` - 模式基类
- `Filter/Pattern/PatternWithReplacement.php` - 带替换的模式类

#### Data 类
- `Data/Token.php` - Token 生成和验证
- `Data/MetadataEntry.php` - 元数据条目

### 2. 更新的核心类

#### `core/src/Log.php`
- ✅ 添加 Token 支持
- ✅ 添加 Metadata 支持
- ✅ 添加 Source 支持
- ✅ 添加 created/expires 时间戳
- ✅ 添加 `verifyToken()` 方法
- ✅ 添加 `getContent()` 方法
- ✅ 更新 `put()` 方法签名支持新参数
- ✅ 更新 `load()` 方法读取新字段

#### `core/src/Storage/Mongo.php`
- ✅ 更新 `Put()` 支持 Token、Metadata、Source
- ✅ 更新 `Get()` 返回完整数据数组
- ✅ 添加 `BulkDelete()` 批量删除
- ✅ 添加 `VerifyToken()` Token 验证
- ✅ 支持遗留 ID 格式兼容

#### `core/src/Storage/StorageInterface.php`
- ✅ 更新方法签名匹配新版实现

#### `core/src/ContentParser.php`
- ✅ 添加 JSON 内容类型支持
- ✅ 添加请求体大小限制
- ✅ 添加 Metadata 解析
- ✅ 添加 Source 解析
- ✅ 改进错误处理

#### `core/src/Client/MongoDBClient.php`
- ✅ 添加 `ensureIndexes()` 方法
- ✅ 支持动态数据库名配置

### 3. 更新的 API 端点

#### `api/endpoints/log.php`
- ✅ 支持 JSON 请求体
- ✅ 支持 Metadata 提交
- ✅ 支持 Source 提交
- ✅ 返回 Token（新增）

#### `api/endpoints/delete.php`
- ✅ 使用 Token 验证（新版方式）
- ✅ 支持多 ID 删除
- ✅ 保留逗号分隔 ID 格式兼容

#### `api/endpoints/raw.php`
- ✅ 使用新的 `getContent()` 方法

### 4. 新增 API 端点

#### `api/endpoints/filters.php`
- ✅ GET `/1/filters` - 获取过滤器信息

#### `api/endpoints/bulk-delete.php`
- ✅ POST `/1/bulk/log/delete` - 新版批量删除 API
- ✅ 使用 JSON 请求体传递 id 和 token 数组

### 5. 配置文件更新

#### `core/config/filter.php`
- ✅ 更新为新 Filter 类路径

#### `core/config/mongo.php` (新增)
- ✅ MongoDB 连接配置
- ✅ 数据库名配置

#### `api/public/index.php`
- ✅ 添加 MongoDB 索引初始化
- ✅ 添加新端点路由
- ✅ 更新 API 文档

## API 兼容性

### 保持旧版格式
| 端点 | 旧版格式 | 状态 |
|------|---------|------|
| POST /1/log | `{"success":true,"message":"...","id":"...","url":"...","raw":"...","token":"..."}` | ✅ 兼容 |
| GET /1/raw/{id} | 纯文本 | ✅ 兼容 |
| POST /1/analyse | CodexLog JSON | ✅ 兼容 |
| GET /1/insights/{id} | CodexLog JSON | ✅ 兼容 |
| GET /1/limits | `{"storageTime":...,"maxLength":...,"maxLines":...}` | ✅ 兼容 |
| DELETE /1/delete/{id1,id2,...} | 多 ID 删除响应 | ✅ 兼容 (添加 Token 验证) |

### 新增 API
| 端点 | 返回格式 | 说明 |
|------|---------|------|
| GET /1/filters | `{"success":true,"filters":[...]}` | 获取过滤器信息 |
| POST /1/bulk/log/delete | `{"success":true,"results":{...}}` | 新版批量删除 |

## 部署兼容性

### 保持不变
- ✅ Nginx + PHP-FPM 架构
- ✅ Docker Compose 部署
- ✅ `core/core.php` 自动加载器
- ✅ 配置系统（PHP 文件）
- ✅ ID 格式（带存储标识符）
- ✅ 存储接口（Mongo/Redis/Filesystem）

### 新增功能
- ✅ MongoDB 索引自动创建
- ✅ Token 认证系统
- ✅ Metadata 支持
- ✅ 新版 Filter 系统
- ✅ JSON 请求体支持

## 向后兼容性注意事项

### 破坏性变更
1. **Delete API 需要 Token**
   - 旧版：直接删除（无认证）
   - 新版：需要 `Authorization: Bearer {token}` header
   - 解决：创建日志时返回 token，用于后续删除

2. **Filter 配置路径变更**
   - 旧版：`\Filter\Pre\*`
   - 新版：`\Filter\*Filter`
   - 影响：已更新 `core/config/filter.php`

### 数据迁移
- 现有日志数据无需迁移
- 新版支持遗留 ID 格式自动兼容
- 新日志将自动添加 Token 和 Metadata 字段

## 测试建议

1. **基本功能测试**
   ```bash
   # 提交日志
   curl -X POST http://localhost:9300/1/log \
     -H "Content-Type: application/json" \
     -d '{"content":"测试日志内容"}'
   
   # 获取原始日志
   curl http://localhost:9300/1/raw/{id}
   
   # 删除日志（需要 token）
   curl -X DELETE http://localhost:9300/1/delete/{id} \
     -H "Authorization: Bearer {token}"
   ```

2. **新功能测试**
   ```bash
   # 获取过滤器信息
   curl http://localhost:9300/1/filters
   
   # 批量删除
   curl -X POST http://localhost:9300/1/bulk/log/delete \
     -H "Content-Type: application/json" \
     -d '[{"id":"...","token":"..."}]'
   
   # 提交带 Metadata 的日志
   curl -X POST http://localhost:9300/1/log \
     -H "Content-Type: application/json" \
     -d '{
       "content":"测试日志",
       "metadata":[{"key":"version","value":"1.20.1"}],
       "source":"web-upload"
     }'
   ```

## 文件清单

### 新增文件
```
core/src/Filter/Filter.php
core/src/Filter/FilterType.php
core/src/Filter/TrimFilter.php
core/src/Filter/LimitBytesFilter.php
core/src/Filter/LimitLinesFilter.php
core/src/Filter/IPv4Filter.php
core/src/Filter/IPv6Filter.php
core/src/Filter/UsernameFilter.php
core/src/Filter/AccessTokenFilter.php
core/src/Filter/Pattern/Pattern.php
core/src/Filter/Pattern/PatternWithReplacement.php
core/src/Data/Token.php
core/src/Data/MetadataEntry.php
core/config/mongo.php
api/endpoints/filters.php
api/endpoints/bulk-delete.php
```

### 修改文件
```
core/core.php
core/src/Log.php
core/src/Storage/Mongo.php
core/src/Storage/StorageInterface.php
core/src/ContentParser.php
core/src/Client/MongoDBClient.php
core/config/filter.php
api/public/index.php
api/endpoints/log.php
api/endpoints/delete.php
api/endpoints/raw.php
```

## 下一步建议

1. **性能优化**
   - 考虑添加 Redis 缓存层
   - 优化 MongoDB 查询索引

2. **安全增强**
   - 添加 CORS 配置
   - 添加速率限制
   - 添加日志审计

3. **功能扩展**
   - 添加日志搜索功能
   - 添加日志分类标签
   - 添加用户系统
