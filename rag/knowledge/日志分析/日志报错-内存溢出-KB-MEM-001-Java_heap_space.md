========
java.lang.OutOfMemoryError: Java heap space
========
报错堆栈：
java.lang.OutOfMemoryError: Java heap space
场景：游戏运行一段时间后崩溃，或进入世界时崩溃
出现的可能原因：
JVM 堆内存分配不足，或模组内存泄漏
解决方案：
1. 启动参数中增大 -Xmx（建议至少 4GB，模组多时 6-8GB）
2. 若已分配充足内存仍崩溃，排查内存泄漏模组（常见：Litematica 大量加载、Create 模组大型结构）
3. 使用 VisualVM 或 Spark 模组进行内存分析