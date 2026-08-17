========
ConcurrentModificationException — 线程安全问题
========
报错堆栈：
java.util.ConcurrentModificationException
出现的可能原因：
多线程并发修改同一集合，通常是模组的线程安全问题
解决方案：
1. 更新相关模组
2. 临时禁用多线程优化模组（如 FerriteCore 的某些配置、Smooth Boot 的非主线程加载）