========
NullPointerException: RenderSystem / BufferBuilder — 渲染优化模组冲突
========
报错堆栈：
NullPointerException: RenderSystem / BufferBuilder
模组：Sodium / Lithium / Iris
场景：加载世界或切换维度时崩溃
出现的可能原因：
渲染优化模组与某些模组的自定义渲染不兼容
解决方案：
1. 更新 Sodium/Iris 到最新版
2. 若仍崩溃，检查与之冲突的模组（常见于动态光源模组、自定义物品渲染模组如 Accessories/Trinkets）