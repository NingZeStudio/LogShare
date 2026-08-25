# Enums

## Entity anchor argument
The entity anchor argument has two valid inputs: `feet` and `eyes`. The resulting [`LookAnchor`](jd:paper:io.papermc.paper.entity.LookAnchor) is mainly used for methods like
[`Entity#lookAt(Position, LookAnchor)`](jd:paper:org.bukkit.entity.Entity#lookAt(io.papermc.paper.math.Position,io.papermc.paper.entity.LookAnchor)) or
[`Player#lookAt(Entity, LookAnchor, LookAnchor)`](jd:paper:org.bukkit.entity.Player#lookAt(org.bukkit.entity.Entity,io.papermc.paper.entity.LookAnchor,io.papermc.paper.entity.LookAnchor)).

### Example usage
```java
public static LiteralCommandNode<CommandSourceStack> entityAnchorArgument() {
    return Commands.literal("entityanchor")
        .then(Commands.argument("arg", ArgumentTypes.entityAnchor())
            .executes(ctx -> {
                final LookAnchor lookAnchor = ctx.getArgument("arg", LookAnchor.class);

                ctx.getSource().getSender().sendRichMessage("You chose <aqua><anchor></aqua>!",
                    Placeholder.unparsed("anchor", lookAnchor.name())
                );
                return Command.SINGLE_SUCCESS;
            }))
        .build();
}
```

### In-game preview

## GameMode argument
The game mode argument works the same way as the first argument of the Vanilla `/gamemode <gamemode>` command. It accepts any of the 4 valid game modes, returning
a [`GameMode`](jd:paper:org.bukkit.GameMode) enum to use in code.

### Example usage
```java
public static LiteralCommandNode<CommandSourceStack> gameModeArgument() {
    return Commands.literal("gamemodearg")
        .then(Commands.argument("arg", ArgumentTypes.gameMode())
            .executes(ctx -> {
                final GameMode gamemode = ctx.getArgument("arg", GameMode.class);

                if (ctx.getSource().getExecutor() instanceof Player player) {
                    player.setGameMode(gamemode);
                    player.sendRichMessage("Your gamemode has been set to <red><gamemode></red>!",
                        Placeholder.component("gamemode", Component.translatable(gamemode))
                    );
                    return Command.SINGLE_SUCCESS;
                }

                ctx.getSource().getSender().sendPlainMessage("This command requires a player!");
                return Command.SINGLE_SUCCESS;
            }))
        .build();
}
```

### In-game preview

## HeightMap argument
The [`HeightMap`](jd:paper:org.bukkit.HeightMap) argument consists of the following, valid inputs: `motion_blocking`, `motion_blocking_no_leaves`, `ocean_floor`, and `world_surface`. It is often
used for declaring relative positioning for data packs or the `/execute positioned over <height_map>` command. E.g. `world_surface`
would mean that the Y coordinate of the surface of the world on the set X/Z values should be used.

### Example usage
```java
public static LiteralCommandNode<CommandSourceStack> heightMapArgument() {
    return Commands.literal("heightmap")
        .then(Commands.argument("arg", ArgumentTypes.heightMap())
            .executes(ctx -> {
                final HeightMap heightMap = ctx.getArgument("arg", HeightMap.class);

                ctx.getSource().getSender().sendRichMessage("You selected <gold><selection></gold>",
                    Placeholder.unparsed("selection", heightMap.name())
                );

                return Command.SINGLE_SUCCESS;
            }))
        .build();
}
```

### In-game preview

## Scoreboard display slot argument
This argument allows you to retrieve a [`DisplaySlot`](jd:paper:org.bukkit.scoreboard.DisplaySlot) enum value from the user.

### Example usage
```java
public static LiteralCommandNode<CommandSourceStack> scoreboardDisplaySlotArgument() {
    return Commands.literal("scoreboarddisplayslot")
        .then(Commands.argument("slot", ArgumentTypes.scoreboardDisplaySlot())
            .executes(ctx -> {
                final DisplaySlot slot = ctx.getArgument("slot", DisplaySlot.class);

                ctx.getSource().getSender().sendPlainMessage("You selected: " + slot.getId());

                return Command.SINGLE_SUCCESS;
            })
        ).build();
}
```

### In-game preview

## Template mirror argument
Here, the user has 3 valid input possibilities: `front_back`, `left_right`, and `none`. You can retrieve the result of
the argument as a [`Mirror`](jd:paper:org.bukkit.block.structure.Mirror) enum value.

### Example usage
```java
public static LiteralCommandNode<CommandSourceStack> templateMirrorArgument() {
    return Commands.literal("templatemirror")
        .then(Commands.argument("mirror", ArgumentTypes.templateMirror())
            .executes(ctx -> {
                final Mirror mirror = ctx.getArgument("mirror", Mirror.class);

                ctx.getSource().getSender().sendPlainMessage("You selected: " + mirror.name());

                return Command.SINGLE_SUCCESS;
            })
        ).build();
}
```

### In-game preview

## Template rotation argument
For the template rotation argument, the user has 4 valid input possibilities: `180`, `clockwise_90`, `counterclockwise_90`, and `none`. You can retrieve the result
of the argument as a [`StructureRotation`](jd:paper:org.bukkit.block.structure.StructureRotation) enum value.

### Example usage
```java
public static LiteralCommandNode<CommandSourceStack> templateRotationArgument() {
    return Commands.literal("templaterotation")
        .then(Commands.argument("rotation", ArgumentTypes.templateRotation())
            .executes(ctx -> {
                final StructureRotation rotation = ctx.getArgument("rotation", StructureRotation.class);

                ctx.getSource().getSender().sendPlainMessage("You selected: " + rotation.name());

                return Command.SINGLE_SUCCESS;
            })
        ).build();
}
```

### In-game preview
