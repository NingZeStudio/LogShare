# Codec

codec 是用于简单地解析 Java 对象的系统，被包含在 Minecraft 所包含的 Mojang 的 DataFixerUpper (DFU) 库中。 在模组环境中，可用作读写 JSON 时 GSON 和 Jankson 的替代方案，尽管因为 Mojang 正在重写许多旧代码以使用 codec，codec 开始变量越来越重要。

codec 与 DFU 的另一个 API `DynamicOps` 一起使用。 一个 codec 定义一个对象的结构，而 dynamic ops 用于定义一个序列化格式，例如 JSON 或 NBT。 这意味着任何 codec 都可以与任何 dynamic ops 一起使用，反之亦然，这样使其极其灵活。

## 使用 codec

### 序列化和反序列化

codec 的基本用法是将对象与特定格式之间进行序列化和反序列化。

由于一些原版的类已经定义了 codec，我们可以将其作为示例进行参考。 Mojang 默认提供了两个 dynamic ops 类，即 `JsonOps` 和 `NbtOps`，它们通常能够涵盖绝大多数使用场景。

现在，假设我们要把一个 `BlockPos` 对象序列化成 JSON 再反序列化回对象。 我们可以分别使用 `BlockPos.CODEC` 中的静态方法 `Codec#encodeStart` 和 `Codec#parse`。
