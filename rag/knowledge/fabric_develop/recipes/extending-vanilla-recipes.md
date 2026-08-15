如果你尝试向已有的工作站点（例如锻造台、工作台或切石机）添加配方，你通常只需要创建配方类、实现其方法、注册序列化器并创建配方 JSON，因为方块、菜单和屏幕逻辑都已（由 Mojang）完成。 让我们看一些示例。

## 概述

每个原版工作站点都有自己的 `RecipeType`，定义在 `RecipeType` 接口中。 每个工作站点都需要某种特定子类型的 `Recipe` 才能正常工作。

请注意，除非你修改底层的菜单，否则你的配方会受限于菜单所能提供的输入和输出。 例如，锻造台有三个输入和一个输出（在原版中，它们通常是 `Optional template`、`Ingredient base`、`Optional addition` 和 `ItemStackTemplate result`）。 然而，在 `Recipe` 类内部，你在配置输入以生成输出方面享有很大自由度。

## 锻造台

让我们创建一种新的锻造配方类型，它将魔咒应用于基础输入物品以生成输出。

锻造台需要实现 `SmithingRecipe` 接口的任意类型，该接口返回 `RecipeTypes.SMITHING`。 在制作新的 `SmithingRecipe` 时，你可以简单地新建一个类并实现 `SmithingRecipe`；但另一种有效的方式是继承 `SimpleSmithingRecipe`（一个原版类），它已经实现了 `SmithingRecipe`。
