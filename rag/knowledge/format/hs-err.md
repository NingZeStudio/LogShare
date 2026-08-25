# hs_err_pid*.log —— JVM 致命错误文件

游戏进程被操作系统/JVM 层杀死（非 Java 异常）时生成于游戏根目录或崩溃目录。
特征：latest.log 戛然而止 + `# A fatal error has been detected` 段落。

## 结构与重点行

```
# A fatal error has been detected ...   ← 入口
#  SIGSEGV (0xb) at pc=0x...            ★ 信号类型
#  JRE version: Java(TM) SE Runtime ... ★ JRE 版本
#  Problematic frame:                    ★ 元凶帧（最重要！）
#  C  [libgl4es.so+0x...]               ← 原生库帧
...
---------------  T H R E A D  ---------------   ← 出事线程名
Java frames: (J=compiled ...)           ← 对应 Java 栈
Dynamic libraries:                      ← 可 grep Mod 的 native 库
```

## 信号速查

| 信号 | 含义 | 高频原因 |
|---|---|---|
| SIGSEGV (11) | 非法内存访问 | 显卡驱动/原生渲染库、Mod native 组件 |
| SIGBUS (7) | 内存对齐/IO 映射错误 | 存档损坏、磁盘满 |
| EXCEPTION_ACCESS_VIOLATION | Windows 等价 SIGSEGV | 同 SIGSEGV |
| OutOfMemory 后 OOM Killer（无 hs_err） | 系统内存耗尽 | 堆分配过大 / 宿主机内存不足 |

## Problematic frame 判读

- `libgl*.so` / `libGLX` / `libgl4es` / `libANGLE` → 渲染层：换驱动/渲染器，别怪 Mod。
- `libjvm.so` 且栈在 GC → 内存参数激进或宿主内存不足。
- 某 Mod 自带 `.so`/`.dll` → 该 Mod 的 native 部分，先更新或移除。
- `Problematic frame: v  ~BufferPool` 类 Java 帧 → 回归 Java 异常排查路径。

## 移动端注意（FCL 等）

Android 上 hs_err 可能落在游戏目录或启动器私有目录；部分启动器会把它重命名为 `crash-dump` 或直接弹窗展示。信号多为 SIGSEGV@libgl4es/libEGL——优先换渲染器而非删 Mod（见 patterns/fcl-android-renderer）。
