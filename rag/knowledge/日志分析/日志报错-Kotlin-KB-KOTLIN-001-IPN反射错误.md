========
KotlinReflectionInternalError — Inventory Profiles Next (IPN)
========
报错堆栈：
KotlinReflectionInternalError
模组：Inventory Profiles Next (IPN)
场景：加入服务器/打开背包时崩溃
出现的可能原因：
IPN 的 Kotlin 反射逻辑与 fabric-language-kotlin 版本或 MC 版本不兼容
解决方案：
1. 移除 IPN 及其前置 libIPN
2. 更新 IPN 到对应 MC 版本
3. 更新 fabric-language-kotlin
备注：常见于 IPN 版本与 MC 版本不对齐时（如 1.21.6 的模组用在 1.21.8 上）