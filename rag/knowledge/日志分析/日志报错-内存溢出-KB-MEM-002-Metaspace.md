========
java.lang.OutOfMemoryError: Metaspace
========
报错堆栈：
java.lang.OutOfMemoryError: Metaspace
出现的可能原因：
Metaspace 不足，通常因大量模组动态生成类
解决方案：
添加 JVM 参数 -XX:MaxMetaspaceSize=512m