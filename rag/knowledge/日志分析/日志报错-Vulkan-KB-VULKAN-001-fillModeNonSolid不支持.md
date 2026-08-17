========
Device[GPU] does not have required feature[fillModeNonSolid] — Vulkan 特性不支持
========
报错堆栈：
Device[GPU] does not have required feature[fillModeNonSolid]
出现的可能原因：
该 GPU 不支持 Vulkan 特性 fillModeNonSolid
解决方案：
目前无解
备注：fillModeNonSolid 指定支持点填充和线框填充模式。如果未启用此功能，则不得使用 VK_POLYGON_MODE_POINT 和 VK_POLYGON_MODE_LINE 枚举值。
Mojang 在快照 26.2 snapshot-1 及以后版本中强制要求 GPU 支持 fillModeNonSolid。移动端 GPU 中只有 Qualcomm 高通骁龙 Adreno、三星使用的 AMD RDNA 的 Xclipse、Apple Silicon 苹果、以及 Imagination Technologies 的 PowerVR 支持此特性。联发科使用的 ARM 公版 Mali、海思使用的 Maleoon 马良均不支持。