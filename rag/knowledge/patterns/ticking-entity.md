# Ticking Entity / Block —— 存档内实体或方块炸服

## 签名 (Signature)

```
Description: Ticking entity
Description: Ticking block entity
Exception in server tick loop
... while ticking ... at BlockPos{x=..,y=..,z=..}
```

## 含义

世界里的某个实体（掉落物、生物、载具）或方块实体（漏斗、机器）每 tick 执行逻辑时抛异常/死循环。**存档已"带病"**，重开也一样炸。

## 常见触发

1. 移除了会生成实体的 Mod，旧实体还在档里
2. Mod 更新后 NBT 结构变化，反序列化半失败
3. 数量爆炸的掉落物/刷怪塔拖垮 tick
4. 区块数据损坏

## 修复步骤

1. 报错里 `Pos[x,y,z]` 与 `Dimension` 给了坐标：删档前先试**传送走/清区域**。
2. 用 NBTExplorer/MCEdit 删除该坐标实体；或临时加"清实体"指令 Mod。
3. 移除 Mod 引起的：装回该 Mod → 进游戏清理其实体 → 再移除。
4. 反复损坏：备份后用原版创建新世界对照验证是否区块级损坏。

## 置信度线索

- **确定**：同一坐标反复出现在崩溃报告 → 点杀该实体即可救活存档。
- **注意**：`Ticking entity` 不影响客户端单机外的其他玩家判断——服务端崩，报告在服务端 logs 里。
