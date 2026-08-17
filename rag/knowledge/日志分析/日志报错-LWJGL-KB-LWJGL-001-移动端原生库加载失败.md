========
UnsatisfiedLinkError / LWJGL 原生库加载失败（移动端）
========
报错堆栈：
java.lang.UnsatisfiedLinkError: liblwjglxx.so / libopenal.so 等 LWJGL 原生库加载异常
场景：移动端 FCL 系列启动器启动时崩溃
出现的可能原因：
LWJGL 原生库与 MC 版本不匹配
解决方案：
升级 FCL：必须首先将 FCL 升级到最新版本。FCL 最新版本能解决绝大多数原生库加载问题。