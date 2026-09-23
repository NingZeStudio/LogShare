========
F3+C / Ctrl+F3+C 强制崩溃 (Manually triggered debug crash / hs_err_pid)
========
报错堆栈：
java.lang.Error: Manually triggered debug crash  （F3+C，Java 层）
EXCEPTION_ACCESS_VIOLATION (Windows) / SIGSEGV (Linux)，寄存器显示写入地址 0x0000000000000000  （Ctrl+F3+C，JVM 层）
场景：长按 F3 + C 超过 10 秒（Ctrl+F3+C 为长按同时多按 Ctrl）
出现的可能原因：
游戏内置的强制终止机制，非 Bug。F3+C 抛出未捕获的 java.lang.Error 绕过 MC 常规异常捕获；Ctrl+F3+C 更底层，直接触发操作系统级空指针解引用（向 0x0 写入），强行生成含完整系统级线程与内存状态的 JVM 致命错误日志。专为游戏死锁时供开发者获取调试信息设计。
判别特征（务必按此区分真实崩溃）：
1. 崩溃前日志必出现"F3+C已被按下"及 8 秒逐秒倒计时的聊天框记录
2. F3+C：异常信息固定为 Manually triggered debug crash，生成 crash-report
3. Ctrl+F3+C：日志无 Reported exception thrown! 与 Manually triggered debug crash，直接被 "A fatal error has been detected by the Java Runtime Environment:" 截断，生成 hs_err_pidXXXX.log
解决方案：
1. 确认存档安全：两种操作均为纯软件层面进程终止，不损坏存档、不影响设备
2. 清理残留日志：前往游戏目录（如 .minecraft）删除生成的 crash-report / hs_err_pidXXXX.log
3. 避免误触：F3+C 正常功能为点按复制坐标到剪贴板，切勿长按超过 10 秒；Ctrl+F3+C 尤注意不要误触 Ctrl 键
可以适当提示：你把这个日志交给我分析，是为了调戏我吗
