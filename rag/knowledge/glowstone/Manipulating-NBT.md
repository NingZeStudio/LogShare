NBT
==========

[NBT](https://minecraft.gamepedia.com/NBT) is a binary tag format used to save information about items, block states and entities in Minecraft. Each one of these can be saved and read from a root NBT compound (`CompoundTag`), which is essentially a map with different tags inside of it. The supported tags to save information are:

- `CompoundTag` (a map of many other tags)
- `ListTag` (a list of different tags of the same type)
- `StringTag` (a simple tag containing a String)
- `ByteTag`, `ShortTag`, `IntTag`, `LongTag`, `DoubleTag` and `FloatTag` (simple tag types containing a byte, short, integer, long, double or float value, respectively)
- `IntArrayTag` and `ByteArrayTag` (tags containing an array of integers or bytes, respectively)

In Glowstone, these tags are implemented in the `net.glowstone.util.nbt` package and extend the [`Tag`](https://glowstone.net/jd/glowstone/net/glowstone/util/nbt/Tag.html) class. There is no NBT API in the Bukkit API or its forks, so you must add the Glowstone server as a dependency directly to manipulate NBT tags. Note that we do not ensure backwards-compatibility when using Glowstone as a dependency.

## Entities
To save or load an Entity from an NBT Compound, you can use the following methods:

**Saving (writing)**
```java
// Create an empty compound tag to save the entity to
CompoundTag tag = new CompoundTag();
// Write the entity data in the compound tag
EntityStorage.save(entity, tag);
```

**Reading**
```java
// Create a compound tag and write some data in it
CompoundTag tag = new CompoundTag();
tag.putString("foo", "bar");
// ...
// Load the data from the tag to the entity
EntityStorage.load(entity, tag);
```