========
KotlinReflectionInternalError / ClassNotFoundException (kotlin.reflect) — Kotlin 前置缺失或版本错配
========
报错堆栈：
KotlinReflectionInternalError
ClassNotFoundException: kotlin.reflect.*
模组：Inventory Profiles Next (IPN) 及任意依赖 Kotlin 的模组
场景：加入服务器/打开背包时崩溃，或启动崩溃
出现的可能原因：
1. fabric-language-kotlin 未安装或版本过低（任何 Kotlin 系模组都可能触发）
2. IPN 的 Kotlin 反射逻辑与 fabric-language-kotlin 版本或 MC 版本不兼容
解决方案：
1. 安装/更新 fabric-language-kotlin 到最新版（首先执行）
2. IPN 场景：移除 IPN 及其前置 libIPN，或更新 IPN 到对应 MC 版本
备注：常见于 IPN 版本与 MC 版本不对齐时（如 1.21.6 的模组用在 1.21.8 上）
