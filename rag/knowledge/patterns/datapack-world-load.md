# 数据包/世界加载失败

## 签名 (Signature)

```
Errors in currently selected datapacks prevented the world from loading
Failed to load world
Registry loading errors / Unknown registry key in ResourceKey[...]
```

## 含义

世界的 `datapacks/` 或存档内注册表数据引用了当前环境不存在的物品/方块/维度——典型于"移除了 Mod 后还想读旧档"。严格说这是**加载拦截**，不是进程崩溃。

## 常见触发

1. 删了内容型 Mod（新物品/生物/维度）再进旧世界
2. 整合包升级后 datapack 版本标记高于客户端支持
3. 世界从更高 MC 版本降级打开

## 修复步骤

1. 弹窗选"Safety query → 以安全模式加载/禁用数据包"先救急进入。
2. 长期：装回提供这些注册项的 Mod，或新建世界迁移建筑。
3. **禁止降级读档**：高版本世界回低版本必炸且不可逆，先备份。

## 置信度线索

- **确定**：签名原文 + 用户承认刚删过 Mod/改过版本。
- **坑**：`Unknown registry key` 列表很长时，逐条对应 Mod 太慢——直接按"删了哪个 Mod"回忆最快。
