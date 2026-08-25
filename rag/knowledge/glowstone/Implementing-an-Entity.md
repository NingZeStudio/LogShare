# Implementing an Entity

You can find most of the information required to implement entities on wikis such as [wiki.vg](http://wiki.vg) and the [Minecraft Wiki](https://minecraft.gamepedia.org).

**Note:** In general, you should wait for the API to have been updated with the interface for the new entity before proceeding with the implementation.

## Class Implementation

The first part consists in making a class for the new entity type. The naming convention is `Glow<Entity Type>`: for example, `GlowLemur`. The class should be located in one of the subpackages under `net.glowstone.entity`.

The class should extend the **implementation of its parent** and implement the **entity's Bukkit interface**:

```java
public class GlowLemur extends GlowLivingEntity implements Lemur {
```

The class is required to have **at least one constructor with the Location parameter only**. This constructor must call the parent's constructor passing the location, the `EntityType` enum field and the entity's default maximum health:

```java
public GlowLemur(Location location) {
    super(location, EntityType.LEMUR, 5);
    // Other defaults here
}
```

Finally, implement the required methods from the API interface.

## Networking

Most of the time, the only changes to the networking will be to update the Entity **Metadata** fields. These fields are initialized in the [`MetadataIndex`](https://github.com/GlowstoneMC/Glowstone/blob/dev/src/main/java/net/glowstone/entity/meta/MetadataIndex.java) enum.

You can find the definition of these fields on wiki.vg's [Entities](http://wiki.vg/Entity_metadata#Entity_Metadata_Format) page. The naming convention for the enum is generally the entity's type name, followed by a descriptive title for the field. If the description or function of a field is not yet known, use `UNKNOWN` for the field title. Make sure you use the correct field index as well (they are sequential in their hierarchy, meaning that the first index of the entity follows the last of it's parent).

When certain data is stored for an entity, it is preferable to keep the values inside of the entity's metadata map instead of having a separate field for it:

```java
public void setEyeColor(DyeColor color) {
    metadata.set(MetadataIndex.LEMUR_EYE_COLOR, color.getDyeData());
}

public DyeColor getEyeColor() {
    return DyeColor.getByDyeData(metadata.getByte(MetadataIndex.LEMUR_EYE_COLOR));
}
``` 

## World Storage

You will need to find the following information on the wikis:

 - The entity's storage ID (found on the entity's infobox under `Entity ID`, on the Minecraft Wiki).
   - For example, the storage ID for Ender Dragon is `ender_dragon`.
 - Documentation about the entity's NBT fields. This information is usually located in the entity's *Data Values* section on the Minecraft Wiki page about the entity.

The first step is to create the **entity's store class**. The "store" is used to serialize and deserialize the entity to/from [[NBT|Manipulating NBT]]. The class should be a subclass of `EntityStore` and be located in the `net.glowstone.io.entity` package. The class should extend the entity parent's store class. The naming convention is `<Entity Type>Store`: for example, `LemurStore`.

```java
class LemurStore extends LivingEntityStore<GlowLemur> {
```
Create a constructor passing the entity's implementation class and `EntityType` enum to the super-constructor.

Then, override the `createEntity(Location, CompoundTag)`, `load(GlowLemur, CompoundTag)`, and `save(GlowLemur, CompoundTag)`methods. The save and load methods **should call the parent method** (using `super.[...]`) before anything else.

```java
@Override
public GlowLemur createEntity(Location location, CompoundTag compound) {
    return new GlowLemur(location);
}

@Override
public void load(GlowLemur entity, CompoundTag tag) {
    super.load(entity, tag);
}

@Override
public void save(GlowLemur entity, CompoundTag tag) {
    super.save(entity, tag);
}
```

With the *Data Values* documentation in hand (or in another tab), you can start implementing the storage fields by using the `tag.getX` methods in the load method, and `tag.putX` methods in the save method. More info can be found in the [[Manipulating NBT]] page.

## Additional Information

 - You need to set the entity's bounding box ("hitbox") size on wiki.vg's [Entities](http://wiki.vg/Entity_metadata#Mobs) page. Use these values (`XZ` and `Y`) in the constructor, as such:
   ```java
   public GlowLemur(Location location) {
       super(location, EntityType.LEMUR, 5);
       setSize(0.4F, 0.8F); // set the bounding box
   }
   ```

## Registering the Entity

Now that you have your implementation class created, you will need to register the relevant classes in Glowstone's registries.

1. Register the entity in the [`EntityRegistry`](https://github.com/GlowstoneMC/Glowstone/blob/dev/src/main/java/net/glowstone/entity/EntityRegistry.java) class. This registry is used to convert entity interfaces into their implementation. By convention, this should be done in alphabetical order.

2. Register the entity's NBT store in [`EntityStorage`](https://github.com/GlowstoneMC/Glowstone/blob/dev/src/main/java/net/glowstone/io/entity/EntityStorage.java) class, in the `static {}` block. This is done by calling the `bind(...)` method with an instance of the store class as the parameter. By convention, the bindings are grouped in entity categories (passive/hostile/objects/etc.)
