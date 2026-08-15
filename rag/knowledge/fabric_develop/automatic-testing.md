此页面解释了如何在你的模组中编写自动化测试。 有两种方法自动化测试你的模组：使用 Fabric Loader JUnit 进行单元测试，或使用 Minecraft 游戏测试框架进行游戏内测试。

单元测试用于测试你代码中的组件，比如方法和工具类；游戏内测试则启动 Minecraft 客户端与服务端来运行你的测试，适用于测试功能和游玩过程。

## 单元测试

由于 Minecraft 模组运行依赖于运行时字节码修改工具比如 mixin，仅仅添加并使用 JUnit 一般不会生效。 这就是为什么 Fabric 提供了 Fabric Loader JUnit，一个针对 Minecraft 模组进行单元测试的 JUnit 插件。

### 配置 Fabric Loader JUnit

首先，我们需要将 Fabric Loader JUnit 添加到开发环境。 将以下依赖添加到你的 `build.gradle`：
