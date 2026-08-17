========
IllegalAccessError / IncompatibleClassChangeError — 模组冲突
========
报错堆栈：
IllegalAccessError / IncompatibleClassChangeError
场景：两个模组修改了同一个类/方法
出现的可能原因：
模组之间的 mixin 冲突或 API 不兼容
解决方案：
1. 逐个禁用模组二分法定位冲突方
2. 检查两个模组是否声明了兼容性
3. 查找社区是否有已知冲突记录