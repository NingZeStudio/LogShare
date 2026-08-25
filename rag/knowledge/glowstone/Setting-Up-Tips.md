## Running Glowstone in your IDE
When you run Glowstone in your IDE, depending on your configuration, you might not be able to type anything into the console.

This happens because of the library jline. This library thinks the console of your IDE isn't supported.

Just add to the VM options / parameters the parameter `-Djline.terminal=jline.UnsupportedTerminal`. Then it should work.

## IntelliJ is slow/freezes

This is a bug with Gradle and the Package Search plugin. Try disabling the Package Search plugin in IntelliJ before using the project.

## Java

Glowstone requires Java 8 update 101 or later. We also have support for developing/building on up to Java 17.

## Lombok
Glowstone uses [Lombok](https://projectlombok.org/download.html), a pre-processor for its code. If you are using an IDE, such as Eclipse or IntelliJ IDEA, you will need to install a Lombok plugin.

* [IntelliJ](https://projectlombok.org/setup/intellij)
* [Eclipse](https://projectlombok.org/setup/eclipse)
* [Netbeans](https://projectlombok.org/setup/netbeans)
* [Visual Studio Code](https://projectlombok.org/setup/vscode)
* More plugins listed on the [Lombok website](https://projectlombok.org/), under Install > IDEs

## Checkstyle
We use [Checkstyle](https://checkstyle.sourceforge.io/index.html) to check and enforce our code style. If you are using an IDE, you can install a checkstyle plugin to help you conform your settings to the project's.

* [IntelliJ](https://plugins.jetbrains.com/plugin/1065-checkstyle-idea)
* [Eclipse](https://checkstyle.org/eclipse-cs/#!/)
* [Netbeans](https://www.sickboy.cz/checkstyle/)
* [Visual Studio Code](https://marketplace.visualstudio.com/items?itemName=shengchen.vscode-checkstyle)
* More plugins/tools listed on the [Checkstyle website](https://checkstyle.sourceforge.io/index.html#Related_Tools_Active_Tools)

## Permissions
The permission structure for the files is as follows:
 * Directories have the permission 744
 * Files have the permission 644

## Maven
[Maven 3.3.9 or later](https://maven.apache.org/download.cgi) must be used. Earlier versions will fail.

## Code Style
Please make sure to read up on the [[Code Style]].