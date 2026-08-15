Minecraft 有个强大的命令建议系统，用在很多地方，例如 `/give` 命令中。 该系统允许您向用户建议命令参数的值，然后他们可以从中选择——这是使你的命令更加用户友好且用起来舒适的好办法。

## 建议提供器

`SuggestionProvider` 用于制作将会发送至客户端的建议的列表。 建议提供器是一个函数式接口，接收一个 `CommandContext` 和 `SuggestionBuilder` 并返回 `Suggestions`。 `SuggestionProvider` 返回 `CompletableFuture`，因为这些建议并不一定立即可用。

## 使用建议提供器

要使用建议提供器，你需要在 argument builder 中调用 `suggests` 方法。 此方法接收一个 `SuggestionProvider`，返回一个附加了新的建议提供器的 argument builder。
