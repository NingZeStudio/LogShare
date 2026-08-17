========
LWJGL 版本不兼容（PC 端）
========
报错堆栈：
java.lang.UnsatisfiedLinkError / GLFW 相关异常
场景：PC 端启动崩溃
出现的可能原因：
LWJGL 版本与 MC 版本或模组不兼容
解决方案：
1. HMCL 启动器：在版本设置 → "LWJGL 版本"中选择 3.3.1 或 3.3.3
2. 若使用 HMCL 的"使用系统安装的 LWJGL3"选项出现问题，取消勾选改用内置版本
3. 更新显卡驱动