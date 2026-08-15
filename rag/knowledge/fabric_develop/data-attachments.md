Fabric API 提供的数据附件 API 允许开发者轻松地将任意数据附加到实体、方块实体、Level 和区块。 附加的数据可以通过编解码器和流编解码器进行存储和同步，因此在使用前你 应该熟悉这些编解码器。

对于不与任何特定 Level 关联的服务器级数据，Fabric 提供了通过 `Level.globalAttachments()` 或 `MinecraftServer.globalAttachments()` 获取的  `GlobalAttachments`。

## 创建数据附加

首先，你需要调用 `AttachmentRegistry.create` 方法。 以下示例创建一个基本数据附加，不会在重启后同步或保留。
