========
StackOverflowError — NBT 递归溢出
========
报错堆栈：
java.lang.StackOverflowError（通常与递归 NBT 读取相关）
模组：常与 Create、Litematica 等涉及大量 NBT 数据的模组相关
出现的可能原因：
NBT 数据结构异常导致无限递归
解决方案：
1. 增大线程栈大小 -Xss4M
2. 若无效，需定位并删除有问题的实体/方块实体数据