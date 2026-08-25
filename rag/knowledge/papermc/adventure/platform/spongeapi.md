# SpongeAPI

Adventure provides a platform for SpongeAPI 7 for *Minecraft: Java Edition* 1.12.

> **Danger**

Adventure platform implementation for SpongeAPI 7 is no longer maintained.
The Adventure team no longer provides support for using these libraries.

We recommend that users of these libraries update to modern software that [natively supports Adventure](/adventure/platform/native) (e.g., SpongeAPI 8+).

Declaring the dependency:

## Usage

The SpongeAPI platform can either be created through Guice dependency injection, or created directly. We recommend using injection, since less boilerplate is required.

An example plugin is fairly straightforward:

```java
@Plugin(/* [...] */)
public class MyPlugin {
    private final SpongeAudiences adventure;

    @Inject
    MyPlugin(final SpongeAudiences adventure) {
        this.adventure = adventure;
    }

    public SpongeAudiences adventure() {
        return this.adventure;
    }
}
```

This sets up a `SpongeAudiences` instance that can provide audiences for players, or any `MessageReceiver`.
