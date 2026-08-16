# Mixin 访问器

Mixin 通常用于修改现有代码以生成和调整行为。 然而，Mixin 同样提供了以访问器 Mixin 的形式，来访问无法直接访问的字段和方法的工具。

类修改器 以 访问拓宽器 的形式提供了类似的工具，但 Mixin 的访问器不需要重新加载 Gradle，并且可以应用于非 Minecraft 目标。

如果需要重写 final 方法、继承 final 类或引用 private 类，仍然需要使用访问拓宽，因为访问器只能针对字段和方法。

## 创建访问器接口

访问器 Mixin 必须始终是一个接口，且只能包含带有 `@Accessor` 或 `@Invoker` 注解的方法。 该接口必须像其他 Mixin 类一样，使用 `@Mixin` 注解进行标记。

按照惯例，访问器接口通常以其目标类名加上 `Accessor` 后缀来命名，并放置在 Mixin 包下的 `accessor` 子包中。
例如 `your.package.mixin.accessor`

## 字段访问器

字段可以通过带有 `@Accessor` 注解的 getter 和/或 setter 方法进行访问：

== 实例字段

Getter/Setter 语法：

实例访问器方法应当带有你的模组 ID 前缀和一个分隔符（通常为 `$` 或 `_`），以确保它不会与任何其他方法发生冲突。

```java
@Accessor("")
FieldType example_mod$getFieldName();

@Accessor("")
void example_mod$setFieldName(FieldType value);
```

示例：
