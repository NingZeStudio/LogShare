# 账户登录与联机实战案例（FCL Issue 提炼）

## 微软正版登录

- **登录失败第一嫌疑：网络**。"开个加速器立马好了"（#106 #995 "挂梯子"）
- **令牌过期/失效** → 去 minecraft.net 官网重新登录刷新一次再回启动器；HMCL 的自动刷新 PR 已同步进 HMCLCore（#344）
- **权限疑虑澄清**：FCL 只请求 `XboxLive.signin offline_access` 范围，不是"完全访问微软账户"；微软账户代码全部来自 HMCL（#1061 #1070）
- **"你的账户尚未获取 Minecraft: Java Edition"** → 买错了版本（Bedrock≠Java），不是登录问题（#745）
- **翻译文件损坏也能导致登录失败**：改过语言文件的先还原试试（#456）
- OAuth 白屏无验证页、新版持续失败等长期存在（#1431 #1166 #1306 open）

## 外置登录（authlib-injector / LittleSkin 等）

- **authlib-injector 自动更新失败** → `authlib-injector.yushi.moe` 域名污染，手动更新或换网（#144 #230）
- LittleSkin 登录后主页皮肤不刷新 → 缓存问题，重启/手动刷新（#512）；披风加载失败（#988）
- helloskin 显示更改皮肤按钮但点击崩 → 该站行为差异（#954）
- 统一通行证不走 UI，用 javaagent 参数接入（见 launcher-issues Java 节，#1364）

## 离线账户

- 未知崩溃时先试离线账户对照——排除账户组件（#161 维护者标准动作）
- 单独修改离线 UUID 需求存在（#1612）

## 联机

### Terracotta / 内置联机
- Terracotta 报错应去 [burningtnt/Terracotta](https://github.com/burningtnt/Terracotta/issues) 反馈，别堆在 FCL（#1429）
- 联机服务成本高："最大问题是钱谁出"（#62）——内置联机是后来的事
- 好友联机功能已内置（入口在多人联机页）（#1666）

### 自建穿透 / 局域网
- 局域网连不上：IP 后跟英文引号+端口；要用虚拟 IP；且这不算启动器的锅（#1088）
- IPv6 直连可行且免穿透（#781）；但 **FCL 连接 IPv6 服务器有已知缺陷**：JDK patch 下 `Failed to find a usable hardware address from the network interfaces`（#360 #284 open）
- frp 方案：自己跑 frpc（[frp-Android](https://github.com/AceDroidX/frp-Android) 包装）或等内置（#329 #1098）

### 版本相关
- **26.2 快照 WebRTC P2P 联机不可用**：`webrtc-java` 没有官方安卓 native 构建（#1579）

## 杂项

- 游戏内录屏需求：手机系统录屏会带上操作按键，社区希望内置可隐藏 UI 的录屏（#1491，未实现）
- 输入法自动唤起做不到，只能手动（Shift+Enter）（#1175）
