Since Glowstone `2018.1.0`, there is a library manager that allows for customization by server owners. This downloads configured libraries to the `lib/` folder (configurable with `folders.libraries`). The library manager does checksum validation on libraries (configurable with `libraries.checksum-validation`) and attempts to fetch libraries from [our Maven repo](https://repo.glowstone.net) (configurable with `libraries.repository-url`) twice (configurable with `libraries.download-attempts`) before quitting.

The library manager is a full Maven client, so it will handle library dependencies appropriately.

## Custom Libraries

You can specify your own libraries for Glowstone to download and use through the `libraries.list` config key in `glowstone.yml`.

This is a list, so you would add new libraries as follows:
```yml
libraries:
  list:
    - group-id: "com.group"
    - artifact-id: "library"
    - version: "1.0.0"
```

`group-id`, `artifact-id` and `version` are required keys, but there are some other optional ones:

* `repository`: Specifies the repository URL to fetch this library from.
* `checksum.type`: A hash algorithm. Valid values: `"sha1"` and `"md5"`. Requires `checksum.value`.
* `checksum.value`: The String value of the file hash, using the algorithm specified in `checksum.type`.
* `exclude-dependencies`: If Maven dependency resolution should not be done on this library. `true` or `false`.

## Compatibility Bundlies
To ensure compatibility with CraftBukkit plugins that expect a certain runtime environment, we have a CraftBukkit compatibility bundle by default that includes all the extra libraries that Glowstone itself does not depend on. This is configurable through the `libraries.compatibility-bundle` key in `glowstone.yml` and may be set to `CRAFTBUKKIT` or `NONE` at this time. At the time of writing, the CraftBukkit bundle includes the following libraries:

* `org.xerial:sqlite-jdbc`
* `mysql:mysql-connector-java`
* `org.apache.logging:log4j.log4j-api`
* `org.apache.logging:log4j.log4j-core`
* `org.apache.commons:commons-lang3`