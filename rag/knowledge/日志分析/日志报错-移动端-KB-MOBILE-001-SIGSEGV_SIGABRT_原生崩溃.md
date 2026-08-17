========
SIGSEGV / SIGABRT — 移动端原生崩溃
========
报错堆栈：
SIGSEGV / SIGABRT (native crash)
场景：移动端（PojavLauncher / MHPC / FoldCraft）运行模组包时崩溃
出现的可能原因：
JVM 内存不足、渲染器不兼容、或原生库加载失败
解决方案：
1. 增大 JVM 内存（-Xmx），但不建议超过设备可用 RAM 的 60%
2. 切换渲染器：Krypton Wrapper（默认）> MobileGlues（模组/光影）> Zink（高阶）> LTW（补充）
3. 降低渲染距离（8Chunk 以下）、关闭粒子、关闭动态光源