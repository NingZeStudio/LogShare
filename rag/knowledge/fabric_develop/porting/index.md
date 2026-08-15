Minecraft 是不断发展中的游戏，新的版本改变游戏的同时也会影响模组开发者。 本文章包含了将模组更新到最新的 Minecraft 稳定版本需要遵循的通用步骤。

这些文档讨论的是从 **26.1** 迁移到 **26.2**， 如果在找其他的迁移，使用右上角的下拉菜单切换到目标版本。

## 更新构建脚本

首先将你的模组的 `gradle/wrapper/gradle-wrapper.properties`、`gradle.properties` 和 `build.gradle` 更新到最新版本。 如果遇到问题，可以考虑参考Fabric 示例模组。

1. 运行以下命令，将 Gradle 更新到最新版：`./gradlew wrapper --gradle-version latest`
2. 在 `gradle.properties`（推荐）或 `build.gradle` 中找到 Minecraft、Fabric Loader、Fabric Loom 和 Fabric API 的版本。 在 Fabric Develop 网站找到 Fabric 组件的推荐版本。
3. 按 IntelliJ IDEA 右上角的刷新按钮刷新 Gradle。 如果按钮不可见，可运行 `./gradlew --refresh-dependencies` 以强制清除缓存。

## 更新代码

构建脚本更新到 26.2 后，现在就可以检查你的模组并更新任何改变的代码，以使其与快照兼容。

- Fabric 博客上的 Fabric for Minecraft 26.2 包含对 Fabric API 26.2.11 版本所做更改的概述。
- Minecraft 博客上的 _Minecraft: Java Edition 26.2_ 是 26.2 引入的功能的官方概述。
- Minecraft Wiki 上的“Java版26.2”是此次更新内容的非官方总结。
- NeoForge 的 _Minecraft 26.1 -> 26.2 Mod Migration Primer_ 涵盖从 26.1 迁移到 26.2，仅关注原始代码更改。
