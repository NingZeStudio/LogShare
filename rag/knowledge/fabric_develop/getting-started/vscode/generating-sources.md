Fabric 工具链允许你通过在本地生成 Minecraft 源代码来访问它，并且你可以使用 Visual Studio Code 方便地浏览它。 要生成源代码，你需要运行 `genSources` Gradle 任务。

这可以通过在 Gradle 视图中运行 **Tasks** > **`fabric`** 下的 `genSources` 任务来完成：

或者，你也可以从终端运行以下命令：

```sh:no-line-numbers
./gradlew genSources
```
