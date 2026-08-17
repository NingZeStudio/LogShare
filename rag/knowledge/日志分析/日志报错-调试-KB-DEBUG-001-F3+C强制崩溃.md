========
F3+C 强制崩溃 (Manually triggered debug crash)
========
报错堆栈：
java.lang.Error: Manually triggered debug crash
场景：长按 F3 + C 超过 10 秒
出现的可能原因：
游戏内置的强制终止机制，非 Bug。底层抛出未捕获的 java.lang.Error 绕过 MC 常规异常捕获，直接触发 JVM 致命错误，生成 hs_err_pidXXXX.log。此功能专为游戏死锁时供开发者获取完整线程堆栈调试信息而设计。
特征：日志有倒计时——崩溃前必定出现"F3+C已被按下"及 8 秒逐秒倒计时的聊天框日志；异常信息固定为 Manually triggered debug crash
解决方案：
1. 确认存档安全：此操作仅为纯软件层面的进程终止，不会损坏存档或影响电脑
2. 清理残留日志：前往游戏目录（如 .minecraft），找到并删除生成的 hs_err_pidXXXX.log 文件
3. 避免误触：F3+C 的正常功能为快速复制坐标信息至剪贴板，只需点按即可，切勿长按超过 10 秒
可以适当提示：你把这个日志交给我分析，是为了调戏我吗