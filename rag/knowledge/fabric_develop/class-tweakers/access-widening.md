访问加宽是一种类调整，用于放宽类、方法和字段的访问限制，并将该变更反映到反编译源码中。
这包括将它们设为 public、可扩展和/或可变。

访问加宽器条目可以是传递性的，从而让依赖你的模组的其他模组也能看到这些变更。

若只是访问字段或方法，使用 Mixin 访问器可能更安全、更简单。
但在以下两种情况下，访问器无法满足需求，必须使用访问加宽：

- 你需要访问 `private`、`protected` 或包私有的类
- 你需要重写 `final` 方法，或继承 `final` 类

不过，与 Mixin 访问器不同，类调整只能作用于原版 Minecraft 类，不能作用于其他模组。

## 访问指令

访问加宽器条目以三种基础指令关键字之一开头，用于指定要应用的修改类型。

关键字之后是参数，通常是加宽的目标。

同一个类、方法或字段可以被多个访问加宽条目所针对，每个条目位于一行。

通过在基础访问指令前添加 `transitive-` 前缀，也可以将访问指令设置为可传递的。

### accessible

`accessible` 可以作用于类、方法和字段：

- 字段和类会被设为 public。
- 方法会被设为 public。如果原本是 private，则还会被设为 final。

将某个方法或字段设为 accessible 时，也会同时将其所属类设为 accessible。

### extendable

`extendable` 仅能作用于类和方法：

- 类会被设为 public 且非 final.
- 方法会被取消 final 限制，若原本为 private 则会被改为 protected。

将某个方法设为 extendable 时，也会同时将其所属类设为 extendable。

### mutable

`mutable` 可以将字段设为非 final。

## 指定目标

在类调整中，类使用其内部名称。 对于字段和方法，你必须指定它们所属的类名、名称以及字节码描述符。

== 类

格式：

```classtweaker:no-line-numbers
    class
```

示例：
