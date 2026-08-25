# LTW 渲染器

## 是什么

社区开发的高性能转译层渲染器，在 Pojav 系启动器（Pojav / Zalith / FCL）圈子里流行：

- 导出 **OpenGL 3.0 级**接口（对比：Zink 4.6、gl4es 2.1）
- 支持 **Sodium** 运行
- 光影部分可用，但**阴影类效果弱/缺失**
- 以帧率表现为卖点，常见于低配机整合包讨论

> ⚠️ 来源与构建较分散（GitHub Actions 构建、第三方分发），**版本更新快且无统一官网**。以插件包内附说明为准。

## 获取

- FCL 插件：[ShirosakiMio/FCLRendererPlugin Releases](https://github.com/ShirosakiMio/FCLRendererPlugin/releases) 中的 `LTW.apk`（注意 .aar/APK 构建日期，越新越好）
- Zalith 用户常从其 Actions 构建页获取

## 高频问题

| 症状 | 原因 | 处理 |
|---|---|---|
| 光影没影子 | 阴影 pass 未实现/不完整 | 属已知限制；要完整光影换 MobileGlues/Zink |
| 与某些 Mod 渲染冲突 | GL 3.0 特性缺口 | 二分定位后给该 Mod 关特效 |
| 版本混乱装错 | 分发渠道分散 | 认准构建日期最新的那个 |

## 横向对比一句话

- 比 gl4es 家族：多了 Sodium 和更高 GL 上限。
- 比 Zink/MobileGlues：光影完整性差一截，但部分低配设备上帧率更稳。
- 社区共识（视频/帖子里高频出现）：**稳定性和光影选 MobileGlues，极限压帧可试 LTW**——两者都试再定。
