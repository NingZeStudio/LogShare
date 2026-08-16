# Loom

Fabric Loom，或者简称为 Loom，是一个 Gradle 插件，用于在 Fabric 生态系统中开发模组。

Loom 提供在开发环境中安装 Minecraft 和模组的实用程序，以便你可以根据 Minecraft 混淆及其在发行版和版本之间的差异对它们进行链接。 Loom 还提供用于 Fabric Loader、Mixin 编译处理和 Fabric Loader 的 jar-in-jar 系统的实用程序的运行配置。

Loom 支持 Minecraft 的 _所有_ 版本，甚至包括那些未被 Fabric API 官方支持的版本，因为它与版本无关。

本页面是 Loom 所有选项和功能的参考。 如果你刚入门，请阅读 Fabric 简介。

## 插件ID

Loom 使用多种不同插件 ID：

- `net.fabricmc.fabric-loom`，对于未混淆的版本（Minecraft 26.1 及以后）
- `net.fabricmc.fabric-loom-remap`，对于混淆的版本（Minecraft 1.21.11 及以前）
- `fabric-loom`（旧版），只向下兼容混淆的版本。 请使用 `net.fabricmc.fabric-loom-remap`
- `net.fabricmc.fabric-loom-companion`，适用于高级多项目场景。 深入了解：子项目

## 依赖子项目

在设置依赖于另一个 Loom 项目的多项目构建时，当依赖于其他项目时，应该使用 `namedElements` 配置。 默认情况下，项目的“输出”会重新映射到中间名称。 `namedElements` 配置包含未重新映射的项目输出。

```gradle
dependencies {
 implementation project(path: ":name", configuration: "namedElements")
}
```

如果你在多项目构建中使用拆分源集，则还需要为其他项目的客户端源集添加依赖项。

```gradle
dependencies {
 clientImplementation this.project(":name").sourceSets.client.output
}
```

## 拆分客户端和通用代码

多年来，服务器崩溃的一个常见原因是模组在服务器上安装时意外调用了客户端专用代码。 较新的 Loom 和 Loader 版本提供了一个选项，要求将所有客户端代码移至其自己的源集。 这是为了在编译时防止问题，但构建仍将产生一个可在任一端运行的 jar 文件。

以下来自 `build.gradle` 文件的片段显示了如何为你的模组启用此功能。 由于你的模组现在将拆分为两个源集，因此你需要使用新的 DSL 来定义模组的源集。 这使 Fabric Loader 能够将模组的类路径分组在一起。 这对于其他一些复杂的多项目设置也很有用。

需要 Minecraft 1.18（推荐 1.19）、Loader 0.14 和 Loom 1.0 或更高版本来拆分客户端和通用代码。
