# 创建命令

创建命令可以允许模组开发者添加一些可以通过命令使用的功能。 这个指南将会教会你如何注册命令和 Brigadier 的一般命令结构。

Brigadier 是 Mojang 为 Minecraft 编写的开源命令解析器和分发器。 它是一个树状命令库，让您可以构建命令和参数的树。

## `Command` 接口

`com.mojang.brigadier.Command` 是一个可以执行指定行为的函数式接口，在某些情况下会抛出 `CommandSyntaxException` 异常。 命令有一个泛型 `S`，定义了_命令来源_的类型。
命令来源提供了命令运行的上下文。 在 Minecraft 中，命令来源通常是 `CommandSourceStack`，代表服务器、命令方块、远程连接（RCON）、玩家或者实体。

`Command` 中的单个方法 `run(CommandContext)`，接收一个 `CommandContext` 作为唯一参数，并返回一个整数。 命令上下文存储命令来源 `S`，并允许你获取参数、查看已解析的命令节点，并查看此命令中使用的输入。

就像其他的函数型接口那样，命令通常用作 lambda 或者方法引用：

```java
Command command = context -> {
    return 0;
};
```

这个整数相当于命令的结果。 通常，小于或等于零的值表示命令失败，什么也不做。 正数则表示命令执行成功并做了一些事情。 Brigadier 提供了一个常量来表示执行成功：`Command#SINGLE_SUCCESS`。

### `CommandSourceStack` 可以做什么？

`CommandSourceStack` 提供了命令运行时的一些额外的上下文，有特定实现， 包括获取运行这个命令的实体、命令执行时所在的世界以及服务器。

可以通过在 `CommandContext` 实例上调用 `getSource()` 方法来获得命令上下文中的命令来源。

```java
Command command = context -> {
    CommandSourceStack source = context.getSource();
    return 0;
};
```

## 注册一个基本命令

可以通过 Fabric API 提供的 `CommandRegistrationCallback` 来注册命令 。

关于如何注册回调，请查看事件指南。

该事件应要在你的模组的初始化器中注册。

这个回调有三个参数：

- `CommandDispatcher dispatcher` - 用于注册、解析和执行命令。 `S` 是命令派发器支持的命令源的类型。
- `CommandBuildContext registryAccess` - 为可能传入特定命令参数的注册表提供抽象方法
- `Commands.CommandSelection environment` - 识别命令将要注册到的服务器的类型。

在模组的入口点中，我们只注册两个简单的命令：
