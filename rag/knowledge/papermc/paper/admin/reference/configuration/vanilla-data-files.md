# Vanilla data files

## `eula.txt`

`eula.txt` is a simple config file that allows you to accept the [EULA](https://aka.ms/MinecraftEULA),
which is required to run the Minecraft server.

## `banned-ips.json`

`banned-ips.json` is a JSON file that stores IP addresses that are not allowed to connect to the server.

> **Caution**

This is a data file used by the server, not a configuration file.

We do not recommend editing this file directly, and this is provided for reference only.

Instead, use the `/ban-ip`, `/pardon-ip`, and `/banlist ips` commands to manage banned-ips.json.

## `banned-players.json`

`banned-players.json` is a JSON file that stores players that are not allowed to connect to the server.

> **Caution**

This is a data file used by the server, not a configuration file.

We do not recommend editing this file directly, and this is provided for reference only.

Instead, use the `/ban`, `/pardon`, and `/banlist players` commands to manage banned-players.json.

## `ops.json`

`ops.json` is a JSON file that stores player's operator status information.

In Vanilla, this is used as a rudimentary permissions system, and to allow certain player to bypass the player limit.

> **Caution**

This is a data file used by the server, not a configuration file.

We do not recommend editing this file directly, and this is provided for reference only.

Instead, use the `/op`, `/deop` commands to manage ops.json.

## `whitelist.json`

`whitelist.json` is a JSON file that stores players that are allowed to connect to the server.

It is used in conjunction with the [`white-list`](/paper/reference/server-properties#white_list) option in `server.properties`.

> **Caution**

This is a data file used by the server, not a configuration file.

We do not recommend editing this file directly, and this is provided for reference only.

Instead, use the `/whitelist` command to manage whitelist.json.

