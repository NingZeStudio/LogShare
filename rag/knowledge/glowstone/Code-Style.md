This page documents code style guidelines. For general contributing guidelines, see [[Contributing]].

# Code Style Rules

Generally, follow [Google style guidelines](https://google.github.io/styleguide/javaguide.html). However, there are a few exceptions/additions:

## Code Guidelines
* The indentation must solely consist of 4 spaces, and 4 spaces for continuation. In other words, the indentation is usually twice what it would be under current Google style.
  * For array literals, the continuation indent is only 4 spaces.
* Import order is done by the default IntelliJ style
* No trailing whitespaces for code lines, comments or configuration files.
* No CRLF line endings, only LF is allowed.
  * For more information about line feeds, see [this article](http://adaptivepatchwork.com/2012/03/01/mind-the-end-of-your-line/).
* All files must end with a newline.
* Avoid nested code structures. For example:
  ```java
  void example() {
      if (!foo()) {
          return;
      }
      Bar bar = bar();
      if (bar == null || bar.foo()) {
          return;
      }
      x();
      y();
      z();
  }
  ```
  is preferred over
  ```java
  void example() {
      if (foo()) {
          Bar bar = bar();
          if (bar != null) {
              if (!bar.foo()) {
                  x();
                  y();
                  z();
              }
          }
      }
  }
  ```

## Javadoc Guidelines
* Private methods must have docs when their function is non-obvious.
* Public classes except protocol internals should have docs.
* Fields on classes that are not simple data structures should have docs.

## Weird Specifics
* In the message codecs, each read from the buffer must be assigned to a variable before the message is constructed. Avoid constructs like `return new XMessage(buffer.readInt());`.

# Validating Code Style

To validate the code style in your branch, run the Maven checkstyle plugin (see below). This is also executed automatically when you build the project.

```
mvn checkstyle:checkstyle
```