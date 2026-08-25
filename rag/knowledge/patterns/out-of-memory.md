# 内存不足（OOM）

## 签名 (Signature)

Java 层：
```
java.lang.OutOfMemoryError: Java heap space
java.lang.OutOfMemoryError: Metaspace
java.lang.OutOfMemoryError: Unable to create native thread
```
系统层（无 crash-report、进程直接消失）：
```
Killed  （终端里出现）
hs_err 或 dmesg 中：Out of memory: Killed process ... (java)
```

## 含义

- `heap space`：堆不够或泄漏（大整合包/高渲染距离/巨型存档）。
- `Metaspace`：类爆炸——Mod 数量极多。
- `native thread`/系统 Killed：**宿主机**内存或线程配额耗尽，与 JVM 堆无关。

## 常见触发

1. -Xmx 给得太大（如 2G 内存机器分 8G）→ 系统 OOM Killer 直接杀
2. -Xmx 太小 → heap space
3. 大量 Mod + 高分辨率材质包 → Metaspace/堆双吃紧

## 修复步骤

1. **先看宿主机总内存**再定堆：`-Xmx ≈ 总内存 × 0.5~0.6`（还要留给系统+native）。
2. Android/FCL：设备物理内存小，建议 2~4G 且开启启动器的自动分配；不要照抄 PC 整合包的 8G 配置。
3. heap space 但宿主内存充裕 → 调大 Xmx；伴随 GC 日志暴涨 → 排查泄漏 Mod（二分）。

## 置信度线索

- **确定**：签名原文 + 知道当前 Xmx 与宿主内存即可定位方向。
- **坑**："游戏直接消失无任何日志" 在 Linux 上默认是 OOM Killer，查 `dmesg | grep -i kill`，别瞎猜 Mod。
