# 伤害类型

伤害类型定义了实体能受到的伤害的种类。 从 Minecraft 1.19.4 开始，创建新的伤害类型的方式已是数据驱动，也就是说由 JSON 文件创建。

## 创建伤害类型

让我们创建一种叫 _土豆_ 的伤害类型。 我们先从为你的自定义伤害创建 JSON 文件开始。 这个文件放在你的模组的 `data` 目录下的 `damage_type` 子目录。

```text:no-line-numbers
resources/data/example-mod/damage_type/tater.json
```

其结构如下：
