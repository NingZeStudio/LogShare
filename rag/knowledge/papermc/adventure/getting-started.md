# Getting started

To use Adventure in your project, you will need to add the following dependency (and repository if using Gradle):

Declaring the dependency:

Need development/snapshot builds? [Using Snapshot Builds](#using-snapshot-builds)

Some platforms already use Adventure natively.
In this case, you will not need to add Adventure as a dependency.
To view the list of platforms that include Adventure, see [Native Support](/adventure/platform/native).

To use Adventure with other platforms, you may wish to look at the platform-specific adapters.
A list of platforms with supported adapters can be found at [Platforms](/adventure/platform).

## Using snapshot builds

To use snapshot builds, you will need to add the following repository:

**Gradle (Kotlin)**

    ```kotlin title="build.gradle.kts"
    repositories {
      maven(url = "https://central.sonatype.com/repository/maven-snapshots/") {
        name = "central-snapshots"
      }
    }
    ```
**Gradle (Groovy)**

    ```groovy title="build.gradle"
    repositories {
      maven {
        name = 'central-snapshots'
        url = 'https://central.sonatype.com/repository/maven-snapshots/'
      }
    }
    ```
**Maven**

    ```xml title="pom.xml"
    <repositories>
      <repository>
        <id>central-snapshots</id>
        <url>https://central.sonatype.com/repository/maven-snapshots/</url>
      </repository>
    </repositories>
    ```
