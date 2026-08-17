========
IllegalArgumentException: ID already registered — ID 冲突
========
报错堆栈：
IllegalArgumentException: ID already registered: xxx
场景：启动崩溃
出现的可能原因：
两个模组注册了相同的物品/方块/附魔 ID（多见于不同版本的同类型模组）
解决方案：
找到重复注册的 ID 对应的两个模组，移除其中一个或寻找替代模组