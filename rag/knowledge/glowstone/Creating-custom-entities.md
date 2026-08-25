# Custom Entities

### Preface
Since Glowstone `2016.0.1.0`, it is possible to register custom entities to your server and spawn them in your world. This allows you to register custom entities with your plugin when running on a Glowstone server.

**Note: It is not possible to create new entities with Glowstone; you cannot create entities that require client modifications like new models and metadata values. You are limited to what the vanilla client can see/hear.**

*Also note that custom loot tables (drops) are not currently supported, you will need to use Bukkit events to have custom loot drops, for the moment.*

### The entity class
The first thing to do when creating a custom entity is to extend an existing *spawnable* entity, such as sheep, pigs, zombies, etc. You will need to create a new class that extends that entity. In this tutorial, we will extend the `GlowSheep` class.

```java
public class CustomSheep extends GlowSheep {
    public CustomSheep(Location location) {
        // your constructor *must* be composed of only one Location argument to work
        super(location);
        // insert your default modifications to your entity here
        setColor(DyeColor.RED); // CustomSheep will always be red
    }
}
```

Now that we have created the base entity class, we can add some custom fields to it. Our custom sheep will always be *RED* by default, but we will make it green when we click on it using Fern, for example. Let's create a `green` field:

```java
public class CustomSheep extends GlowSheep {
    private boolean green = false; // whether our sheep is green
    public CustomSheep(Location location) {
        // your constructor *must* be composed of only one Location argument to work
        super(location);
        // insert your default modifications to your entity here
        setColor(DyeColor.RED); // CustomSheep will always be red
    }

    public void setGreen(boolean green) { // our setter for the "green" field
        this.green = green;
        setColor(DyeColor.GREEN); // make our sheep green
    }

    public boolean isGreen() { // our getter for the "green" field
        return this.green;
    }
}
```

Now, we can override the entity's `entityInteract` method to check when this entity is being clicked on.
```java
public class CustomSheep extends GlowSheep {
    private boolean green = false; // whether our sheep is green
    public CustomSheep(Location location) {
        // your constructor *must* be composed of only one Location argument to work
        super(location);
        // insert your default modifications to your entity here
        setColor(DyeColor.RED); // CustomSheep will always be red
    }

    public void setGreen(boolean green) { // our setter for the "green" field
        this.green = green;
        setColor(DyeColor.GREEN); // make our sheep green
    }

    public boolean isGreen() { // our getter for the "green" field
        return this.green;
    }

    @Override
    public boolean entityInteract(GlowPlayer player, InteractEntityMessage message) {
        if (message.getAction() == InteractEntityMessage.Action.INTERACT) { // check if the sheep is being clicked on
            ItemStack holding = InventoryUtil.itemOrEmpty(player.getItemInHand()); // the item the player is holding
            if (holding.getType() == Material.LONG_GRASS && holding.getDurability() == 2 && !isGreen()) { // check if the item is Fern, and the sheep is not already green
                setGreen(true);
                return true;
            }
        }
        return false;
    }
}
```

### The entity store
Now that we have created our entity class, we have to create a *store* in order to save data for each entity. In this case, we will want to store whether or not the sheep is green when saving the world. *Stores* are essentially storage managers to save and load data from the world's NBT storage files.

Let's create a `CustomSheepStore` class for our CustomSheep's store.
```java
public class CustomSheepStore extends AnimalStore<CustomSheep> {
    public CustomSheepStore() {
        super(CustomSheep.class, "custom_sheep"); // create the store for the CustomSheep entity (named "custom_sheep")
    }

    @Override
    public void save(CustomSheep entity, CompoundTag tag) {
        super.save(entity, tag); // save default data from the entity (health, location, etc.)
        if (entity.isGreen()) {
            tag.putBool("Green", true); // save the color of the CustomSheep if it's green
        }
    }

    @Override
    public void load(CustomSheep entity, CompoundTag tag) {
        super.load(entity, tag);
        if (tag.isByte("Green") {
            entity.setGreen(tag.getBool("Green")); // set whether or not the entity is green from the NBT data
        }
    }
}
```

### The entity descriptor
Now that we have created an entity class and its store, we can register it to the server so we can spawn and load our entity. We can achieve this by creating a *descriptor* (`CustomEntityDescriptor`). **It is important to register your entities *before* worlds are loaded in, because the entity stores have to be registered before they are loaded. You will need to register your entity inside the `onLoad` method of your plugin.**

In our plugin class, we will need to register our entities inside the `onLoad` method:
```java
public class CustomEntityPlugin extends JavaPlugin {

    @Override
    public void onLoad() {
        // register custom entities HERE
    }
}
```

A *descriptor* is simply an object describing your entity that you will pass to the Entity Registry. You can create this object using the `CustomEntityDescriptor` constructor, `CustomEntityDescriptor(Class of entity, Plugin, Name, Store)`, and then register it using `EntityRegistry.registerCustomEntity(descriptor)`:

```java
public class CustomEntityPlugin extends JavaPlugin {

    @Override
    public void onLoad() {
        // register custom entities HERE
        CustomEntityDescriptor<CustomSheep> descriptor = new CustomEntityDescriptor<>( // our descriptor
            CustomSheep.class,     // our entity class
            this,                  // our plugin
            "custom_sheep",        // the identifier/name of our entity
            new CustomSheepStore() // our entity store
        );
       EntityRegistry.registerCustomEntity(descriptor); // register the entity
    }
}
```

### Spawning the entity
You can now spawn your entity using the summon command: `/summon custom_sheep`.

If you wish to spawn your entity using your plugin, you will need to use a specific method from `GlowWorld` as to not conflict with vanilla entities:

```java
World world = [...];
Location location = [...];

GlowWorld gWorld = (GlowWorld) world; // cast your world to the implementation
CustomSheep entity = (CustomSheep) gWorld.spawnCustomEntity(location, "custom_sheep"); // use the GlowWorld#spawnCustomEntity(location, entityId) method to spawn custom entities
```

## Entity AI
Entity AI is represented as "Tasks" in Glowstone. It is possible to create entity AI tasks since version `2017.0.1.1`, and only to Living entities (entities [indirectly] extending `GlowLivingEntity`). The Entity Task API is not very stable yet and is subject to frequent change. Once again, this API is not exposed to Glowkit, so you will need to use Glowstone as your dependency.

You can find utilities for pathfinding in the [`net.glowstone.util.pathfinding`](https://github.com/GlowstoneMC/Glowstone/tree/dev/src/main/java/net/glowstone/util/pathfinding) package.

Tasks are pulsed every tick in a separate thread from the world. As such, you should not interact with the world's blocks directly as to prevent concurrency issues. *To be continued...*
