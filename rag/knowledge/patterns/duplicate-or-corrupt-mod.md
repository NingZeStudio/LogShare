# 重复 Mod / 文件损坏

## 签名 (Signature)

Fabric:
```
Duplicate mods found:
  - Mod 'x' ... already present
```
Forge:
```
Duplicate Mods Found / Multiple files for mod file X found
```

或文件损坏类：
```
ZipException: error in opening zip file
Mod file ... is not a valid mod file
invalid distance too far back
```

## 含义

- **重复**：同一个 Mod id 出现多个 jar（常见：新旧版本共存、jar 与解压目录并存）。
- **损坏**：下载中断导致 jar 不是合法 zip，加载器读 mods.toml/fabric.mod.json 失败。

## 修复步骤

1. 重复：进 `mods/` 按 id 排序，**只留一个最新版**；注意 `.jar.disabled` 之外的隐藏副本。
2. 损坏：删除报错指名的 jar，重新下载；校验文件大小是否与发布页一致。
3. 整合包用户：优先用启动器"验证/重装整合包"功能而不是手动补。

## 置信度线索

- **确定**：签名原文出现 → 机械操作即可解决。
- **坑**：`ZipException` 也可能是磁盘/存储坏了（多文件同时损坏时先查盘）。
