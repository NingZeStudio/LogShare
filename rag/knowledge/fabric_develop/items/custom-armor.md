盔甲增强玩家的防御，抵御来自生物和其他玩家的攻击。

## 创建盔甲材质类

从技术上讲，你不需要为你的盔甲材质设立专门的类别，但无论如何，对于你需要的静态场数量来说，这都是很好的做法。

对于本例，我们将创建一个 `GuiditeArmorMaterial` 类来存储我们的静态字段。

### 基础耐久度

在创建我们的盔甲物品时，这个常量将在 `Item.Properties#maxDamage(int damageValue)` 方法中使用，当我们稍后创建 `ArmorMaterial` 对象时，它也是 `ArmorMaterial` 构造函数中的参数。
