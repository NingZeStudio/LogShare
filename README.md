# LogShare

## 项目简介

LogShare 是一款 Minecraft 和 Hytale 日志分析与快速诊断工具，基于 Aternos Codex 构建。

LogShare v1.5.1 正式发布，LogShare.CN同步上线；本次更新带来了两大重要功能和支持性更新：
新增基于ID的大模型智能日志分析接口。
新增基于日志内容的不落盘大模型智能日志分析接口，适用于隐私保护场景。

完善了日志脱敏功能，修复特定情况下IPv6会脱敏失败的问题。

日志初步解析扩展，更新Codex-Minecraft至5.1.0，带来的新特性如下：
检测 Spigot 26.1
检测 NeoForge 26.1 客户端日志
检测数据包解析失败的问题
检测 Forge 26.1 客户端日志
检测开发环境中的 Fabric 客户端日志
支持官方 Minecraft 启动器的日志

## 许可证

本项目采用 [MIT License](LICENSE) 开源。

## 注意

该仓库仅平台代码开源及托管，不建议私有部署使用。如需使用请先进行定制化修改。