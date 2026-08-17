========
java.lang.NoClassDefFoundError / java.lang.ClassNotFoundException — 前置模组缺失
========
报错堆栈：
java.lang.NoClassDefFoundError / java.lang.ClassNotFoundException
场景：游戏启动或进入世界时崩溃
出现的可能原因：
某模组的前置模组未安装
解决方案：
根据缺失的类名判断前置模组（如缺失 cloth-config 相关类则需安装 Cloth Config），在 Modrinth 搜索该模组的依赖页面确认完整前置列表