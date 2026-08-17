========
Ctrl+F3+C 强制 JVM 崩溃 (hs_err_pid 日志)
========
报错堆栈：
EXCEPTION_ACCESS_VIOLATION (Windows) / SIGSEGV (Linux)
场景：游戏中长按 Ctrl + F3 + C 超过 10 秒
出现的可能原因：
与 F3+C 抛出 Java Throwable 不同，此组合键会直接在底层触发操作系统级别的空指针解引用（向内存地址 0x0000000000000000 写入数据），强行绕过 Minecraft 的异常捕获机制，目的是强制生成包含完整系统级线程和内存状态的 JVM 致命错误日志。
特征：日志有倒计时——崩溃前必定出现"F3+C已被按下"及 8 秒逐秒倒计时的聊天框日志；日志中不会出现常规的 Reported exception thrown! 和 Manually triggered debug crash，而是直接被一段 "A fatal error has been detected by the Java Runtime Environment:" 截断；游戏目录下生成的是 hs_err_pidXXXX.log；错误类型必然是 EXCEPTION_ACCESS_VIOLATION，寄存器信息中会显示尝试写入空地址（writing address 0x0000000000000000）
解决方案：
1. 确认存档安全：此操作仅为底层强制杀进程，不会损坏存档
2. 清理致命日志：前往游戏目录（如 .minecraft），找到并删除生成的 hs_err_pidXXXX.log 文件
3. 避免误触：F3+C 的正常功能为复制坐标，快速点按即可，切勿长按超过 10 秒，尤其注意不要误触 Ctrl 键
可以适当提示：你把这个日志交给我分析，是为了调戏我吗