# 项目结构

本页将介绍 Fabric 模组项目的结构，以及项目中每个文件和文件夹的作用。

## `fabric.mod.json`

`fabric.mod.json` 文件是描述你的模组给 Fabric Loader 的主文件。 它包含模组的 ID、版本和依赖关系等信息。

`fabric.mod.json` 文件中最重要的字段是：

- `id`：模组的 ID，应该是唯一的。
- `name`：模组的名称。
- `environment`：你的模组运行环境，可以是 `client`（仅客户端）、`server`（仅服务端）和 `*`（双端）。
- `entrypoints`：你的模组提供的入口点，例如 `main` 或 `client` 。
- `depends`：模组的依赖模组/库。
- `mixins`：模组提供的 Mixin。

## 入口点

如前所述，`fabric.mod.json` 文件包含一个名为 `entrypoints` 的字段——该字段用于指定你的模组提供的入口点。

模板模组生成器默认创建 `main` 和 `client` 入口点：

- `main`入口点用于通用代码，它包含在一个实现了 `ModInitializer` 的类中
- `client`入口点用于客户端特定代码，其类实现了 `ClientModInitializer`

这些入口点将会在游戏启动时依次调用。

这是一个简单的 `main` 入口点示例，当游戏启动时，它会向控制台记录一条消息：
