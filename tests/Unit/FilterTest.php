<?php

use App\Filter\IPv4Filter;
use App\Filter\IPv6Filter;
use App\Filter\IPv6ShortFilter;
use App\Filter\UuidFilter;
use App\Filter\XuidFilter;
use App\Filter\SessionTokenFilter;
use App\Filter\ClientIdFilter;
use App\Filter\CoordinateFilter;
use App\Filter\UsernameFilter;
use App\Filter\AccessTokenFilter;

function applyPreFilters(string $input): string
{
    foreach (\App\Config::Get('filter')['pre'] as $class) {
        $input = $class::filter($input);
    }
    return $input;
}

test('IPv4Filter masks standard IPv4 addresses', function () {
    $input = 'Player[/192.168.1.1:25565] logged in';
    $expected = 'Player[/**.**.**.**:25565] logged in';
    expect(IPv4Filter::filter($input))->toBe($expected);
});

test('IPv4Filter preserves localhost and private ranges', function () {
    $input = '127.0.0.1 and 10.0.0.1 and 192.168.1.1';
    $output = IPv4Filter::filter($input);
    expect($output)->toContain('127.0.0.1');
    expect($output)->not->toContain('10.0.0.1');
    expect($output)->not->toContain('192.168.1.1');
});

test('IPv6Filter masks full IPv6 addresses', function () {
    $input = 'Connected from 2001:0db8:85a3:0000:0000:8a2e:0370:7334';
    $output = IPv6Filter::filter($input);
    expect($output)->toContain('****:****:****:****:****:****:****:****');
});

test('IPv6Filter preserves loopback', function () {
    $input = '::1 and ::ffff:127.0.0.1';
    $output = IPv6Filter::filter($input);
    expect($output)->toContain('::1');
});

test('IPv6ShortFilter masks compressed IPv6 loopback preserved', function () {
    $input = 'fe80::1%eth0 and 2001:db8::/32 and ::1';
    $output = IPv6ShortFilter::filter($input);
    expect($output)->toContain('****:****:****:****:****:****:****:****');
    expect($output)->toContain('::1');
});

test('UuidFilter masks standard UUID', function () {
    $input = 'Player UUID: 550e8400-e29b-41d4-a716-446655440000';
    $output = UuidFilter::filter($input);
    expect($output)->toContain('********-****-****-****-************');
    expect($output)->not->toContain('550e8400-e29b-41d4-a716-446655440000');
});

test('UuidFilter masks UUID with braces', function () {
    $input = '{550e8400-e29b-41d4-a716-446655440000}';
    $output = UuidFilter::filter($input);
    expect($output)->toContain('{********-****-****-****-************}');
});

test('UuidFilter masks URN UUID', function () {
    $input = 'urn:uuid:550e8400-e29b-41d4-a716-446655440000';
    $output = UuidFilter::filter($input);
    expect($output)->toContain('urn:uuid:********-****-****-****-************');
});

test('UuidFilter masks no-dash UUID', function () {
    $input = '550e8400e29b41d4a716446655440000';
    $output = UuidFilter::filter($input);
    expect($output)->toContain('****************************');
});

test('XuidFilter masks 16-digit XUID', function () {
    $input = 'xuid: 2535412345678901 and XUID=2535412345678902';
    $output = XuidFilter::filter($input);
    expect($output)->toContain('xuid:"****************"');
    expect($output)->not->toContain('2535412345678901');
    expect($output)->not->toContain('2535412345678902');
});

test('XuidFilter masks xboxUserId field', function () {
    $input = 'xboxUserId:"2535412345678901"';
    $output = XuidFilter::filter($input);
    expect($output)->toContain('xboxUserId:"****************"');
});

test('SessionTokenFilter masks JWT access_token', function () {
    $input = 'accessToken:"eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjMifQ.dummy"';
    $output = SessionTokenFilter::filter($input);
    expect($output)->toContain('accessToken:"********"');
});

test('SessionTokenFilter masks Authorization header', function () {
    $input = 'Authorization: Bearer eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjMifQ.dummy';
    $output = SessionTokenFilter::filter($input);
    expect($output)->toContain('Authorization: Bearer ********');
});

test('SessionTokenFilter masks X-Access-Token header', function () {
    $input = 'X-Access-Token: eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjMifQ.dummy';
    $output = SessionTokenFilter::filter($input);
    expect($output)->toContain('X-Access-Token: ********');
});

test('SessionTokenFilter masks sessionId with token:uuid format', function () {
    $input = 'sessionId:"token:550e8400-e29b-41d4-a716-446655440000"';
    $output = SessionTokenFilter::filter($input);
    expect($output)->toContain('sessionId:"********:********-****-****-****-************"');
});

test('ClientIdFilter masks clientId field', function () {
    $input = 'clientId:"abc123def456ghi789jkl"';
    $output = ClientIdFilter::filter($input);
    expect($output)->toContain('clientId:"********"');
});

test('ClientIdFilter masks base64 clientId', function () {
    $input = 'clientId:"YWJjZGVmZ2hpamtsbW5vcA=="';
    $output = ClientIdFilter::filter($input);
    expect($output)->toContain('clientId:"********"');
});

test('ClientIdFilter masks 32-char hex clientId', function () {
    $input = 'clientId:"550e8400e29b41d4a716446655440000"';
    $output = ClientIdFilter::filter($input);
    expect($output)->toContain('clientId:"********"');
});

test('ClientIdFilter masks --clientId CLI arg', function () {
    $input = '--clientId abc123def456ghi789jkl';
    $output = ClientIdFilter::filter($input);
    expect($output)->toContain('--clientId ********');
});

test('CoordinateFilter masks BlockPos', function () {
    $input = 'BlockPos(100, 64, -200)';
    $output = CoordinateFilter::filter($input);
    expect($output)->toContain('BlockPos(*****, *****, *****)');
});

test('CoordinateFilter masks Vec3d', function () {
    $input = 'Vec3d(100.5, 64.0, -200.25)';
    $output = CoordinateFilter::filter($input);
    expect($output)->toContain('Vec3d(*****, *****, *****)');
});

test('CoordinateFilter masks "at (x, y, z)" format', function () {
    $input = 'logged in at (8.30, 136.0, -6.41)';
    $output = CoordinateFilter::filter($input);
    expect($output)->toContain('at (*****, *****, *****)');
});

test('CoordinateFilter masks local coords ^ ^ ^', function () {
    $input = '^ ^ ^ and ^1 ^ ^-1';
    $output = CoordinateFilter::filter($input);
    expect($output)->toContain('^ ^ ^');
});

test('UsernameFilter masks Windows user paths', function () {
    $input = 'C:\Users\Steve\AppData\Roaming\.minecraft\logs\latest.log';
    $output = UsernameFilter::filter($input);
    expect($output)->toContain('C:\Users\********\AppData');
});

test('UsernameFilter masks Linux home paths', function () {
    $input = '/home/steve/.minecraft/logs/latest.log';
    $output = UsernameFilter::filter($input);
    expect($output)->toContain('/home/********/.minecraft');
});

test('UsernameFilter masks macOS Users paths', function () {
    $input = '/Users/steve/Library/Application Support/minecraft/logs/latest.log';
    $output = UsernameFilter::filter($input);
    expect($output)->toContain('/Users/********/Library');
});

test('UsernameFilter masks USERNAME= env var', function () {
    $input = 'USERNAME=Steve';
    $output = UsernameFilter::filter($input);
    expect($output)->toBe('USERNAME=********');
});

test('AccessTokenFilter masks accessToken in JSON', function () {
    $input = 'accessToken:"secret-token-123"';
    $output = AccessTokenFilter::filter($input);
    expect($output)->toContain('accessToken:"********"');
});

test('AccessTokenFilter masks access_token snake_case', function () {
    $input = 'access_token:"secret-token-123"';
    $output = AccessTokenFilter::filter($input);
    expect($output)->toContain('access_token:"********"');
});

test('AccessTokenFilter masks X-Access-Token header', function () {
    $input = 'X-Access-Token: secret-token-123';
    $output = AccessTokenFilter::filter($input);
    expect($output)->toContain('X-Access-Token: ********');
});


test("EncodingFilter sanitizes invalid UTF-8 bytes", function () {
    $dirty = "正常开头 " . "\xff\xfe\x80" . " GBK 残留行";
    $clean = \App\Filter\EncodingFilter::filter($dirty);

    expect(mb_check_encoding($clean, "UTF-8"))->toBeTrue();
    expect($clean)->toContain("正常开头");
    expect(json_encode(["c" => $clean], JSON_UNESCAPED_UNICODE))->not->toBeFalse();
});

test("EncodingFilter passes valid UTF-8 through unchanged", function () {
    $ok = "合法内容 line2\n中文 English mixed 123";
    expect(\App\Filter\EncodingFilter::filter($ok))->toBe($ok);
});
test('Filter chain applies all filters in order', function () {
    $input = 'Player Steve[/192.168.1.1:25565] UUID: 550e8400-e29b-41d4-a716-446655440000 at (100, 64, -200)';
    $output = applyPreFilters($input);
    
    expect($output)->not->toContain('192.168.1.1');
    expect($output)->toContain('**.**.**.**');
    expect($output)->not->toContain('550e8400-e29b-41d4-a716-446655440000');
    expect($output)->toContain('********-****-****-****-************');
    expect($output)->not->toContain('(100, 64, -200)');
    expect($output)->toContain('(*****, *****, *****)');
});

test('Filter chain masks sensitive data in simulated Minecraft log', function () {
    $input = <<<'LOG'
[10:11:14] [main/INFO]: Found mod file "cbc_at_Neoforge_1.21.1_0.1.4c.jar" [locator: {mods folder locator at /storage/emulated/0/FCL/.minecraft/versions/1.21.1/mods}, reader: mod manifest]
[10:11:14] [main/INFO]: Loading renderer: OpenGL 4.5
[10:11:14] [main/INFO]: Minecraft 1.21.1-4.0.0 (client) has launched!
[10:11:15] [main/INFO]: Connected to server at 192.168.1.100:25565
[10:11:16] [main/INFO]: UUID of player Notch is 550e8400-e29b-41d4-a716-446655440000
[10:11:16] [main/INFO]: User Notch logged in with entity id 42 at (100.5, 64.0, -200.25)
[10:11:17] [Worker-Main-2/INFO]: User Notch joined the game
[10:11:18] [Render thread/INFO]: Rendered 5000 blocks, looking at (200, 70, -300)
[10:11:20] [main/INFO]: Saving chunks for level world
[10:11:21] [Server thread/INFO]: Stopping server
[10:11:22] [main/FATAL]: Exception in server tick loop
[10:11:22] [main/ERROR]: Failed to bind to address ::ffff:192.168.1.50:25565
[10:11:23] [main/WARN]: Session ID: token:550e8400-e29b-41d4-a716-446655440000
[10:11:24] [main/INFO]: accessToken:"eyJhbGciOiJIUzI1NiJ9.dummyToken123456789"
[10:11:25] [main/INFO]: clientId:"550e8400e29b41d4a716446655440000"
LOG;

    $output = applyPreFilters($input);

    expect($output)->toContain('Minecraft 1.21.1');
    expect($output)->not->toContain('192.168.1.100');
    expect($output)->toContain('**.**.**.**');
    expect($output)->not->toContain('550e8400-e29b-41d4-a716-446655440000');
    expect($output)->toContain('********-****-****-****-************');
    expect($output)->not->toContain('100.5, 64.0, -200.25');
    expect($output)->toContain('(*****, *****, *****)');
    expect($output)->not->toContain('200, 70, -300');
    expect($output)->toContain('****:****:****:****:****:****:****:****');
    expect($output)->toContain('**.**.**.**');
    expect($output)->toContain('token:********-****-****-****-************');
    expect($output)->toContain('accessToken:"********"');
    expect($output)->toContain('clientId:"****************************"');
    expect($output)->toContain('/storage/emulated/0/FCL/.minecraft');
    expect($output)->toContain('cbc_at_Neoforge_1.21.1_0.1.4c.jar');
});

test('Filter chain masks sensitive data in multi-loader Minecraft logs', function () {
    $input = <<<'LOG'
=== Client (Vanilla + OptiFine) ===
[13:45:22] [main/INFO]: Setting user: PlayerOne
[13:45:22] [main/INFO]: Backend library: LWJGL version 3.3.1
[13:45:23] [main/INFO]: Refresh complete
[13:45:24] [main/INFO]: OpenGL 4.5.0 NVIDIA
[13:45:25] [main/INFO]: Loading 138 recipes
[13:45:26] [main/INFO]: Loaded 7 advancements
[13:45:30] [main/INFO]: Connecting to mc.example.com, 192.168.1.10:25565
[13:45:31] [main/INFO]: UUID of PlayerOne is abcdef01-1234-5678-9abc-def012345678
[13:45:32] [main/INFO]: User PlayerOne logged in with entity id 42 at (100.5, 64.0, -200.25)
[13:45:33] [Render thread/INFO]: Rendered 5000 blocks, looking at (200, 70, -300)
[13:45:35] [main/INFO]: Session ID: token:abcdef01-1234-5678-9abc-def012345678
[13:45:36] [main/INFO]: accessToken:"eyJhbGciOiJIUzI1NiJ9.dummyToken123456789"

=== Client (Fabric + Sodium) ===
[13:46:10] [main/INFO]: Loading Minecraft 1.21.1 with Fabric Loader 0.15.11
[13:46:10] [main/INFO]: Loading 27 mixins from fabric-api
[13:46:11] [Render thread/INFO]: Sodium initialized at 200 FPS
[13:46:12] [main/INFO]: Connected to 10.0.0.5:25565
[13:46:13] [main/INFO]: Joined world [SP] level
[13:46:14] [main/INFO]: Player position: BlockPos(100, 64, -200)

=== Client (Forge + NeoForge) ===
[13:47:01] [main/INFO]: Forge mod loader loading version 47.3.0 for Minecraft 1.21.1
[13:47:01] [main/INFO]: NeoForge 21.1.0 beta starting
[13:47:02] [main/INFO]: Found mod file sodium-0.5.8.jar
[13:47:02] [main/INFO]: Found mod file sodium-extra-0.5.8.jar
[13:47:03] [main/INFO]: Applying compatibility fixes for 120 mods
[13:47:05] [main/INFO]: Connecting to play.example.com, 172.16.0.5:25565

=== Dedicated Server (Vanilla) ===
[13:48:00] [main/INFO]: Starting minecraft server version 1.21.1
[13:48:00] [main/INFO]: Loading properties
[13:48:01] [main/INFO]: Generating keypair
[13:48:02] [Server thread/INFO]: Starting Minecraft server on *:25565
[13:48:03] [Server thread/INFO]: Using default channel type
[13:48:05] [Server thread/INFO]: Player Notch joined the game
[13:48:06] [User Authenticator #1/INFO]: UUID of player Notch is 550e8400-e29b-41d4-a716-446655440000
[13:48:07] [Server thread/INFO]: Notch logged in with entity id 42 at (100.5, 64.0, -200.25)
[13:48:10] [Server thread/INFO]: Saving chunks for level world
[13:48:12] [Server thread/INFO]: Stopping the server

=== Dedicated Server (Forge) ===
[13:49:00] [main/INFO]: Starting Forge server 47.3.0 for Minecraft 1.21.1
[13:49:00] [main/INFO]: Loading 58 mods
[13:49:01] [Server thread/INFO]: Listening on :::25565
[13:49:03] [Server thread/INFO]: Player Steve connected with IP 203.0.113.10
[13:49:05] [Server thread/INFO]: Saving world
[13:49:08] [main/ERROR]: Exception in server tick loop
[13:49:08] [main/ERROR]: Failed to bind to address ::ffff:203.0.113.20:25565

=== Integrated Server (singleplayer) ===
[13:50:00] [main/INFO]: Loaded 7 recipes
[13:50:01] [main/INFO]: Loaded 25 advancements
[13:50:02] [Server thread/INFO]: Starting integrated minecraft server version 1.21.1
[13:50:02] [Server thread/INFO]: Generating new map chunks
[13:50:03] [Server thread/INFO]: Player Jeb joined the game
[13:50:04] [Render thread/INFO]: Rendered 12000 blocks
[13:50:05] [Server thread/INFO]: Saving chunks for level world
[13:50:06] [main/INFO]: Stopping singleplayer server

=== Proxy (BungeeCord) ===
[13:51:00] [BungeeCord/main/INFO]: BungeeCord 1.20-R0.1-SNAPSHOT starting up
[13:51:01] [BungeeCord/main/INFO]: Listening on /0.0.0.0:25577
[13:51:02] [BungeeCord/main/INFO]: Connected to backend server: 192.168.1.20:25565
[13:51:03] [BungeeCord/net down-handler/INFO]: Player Notch connected
[13:51:04] [BungeeCord/net down-handler/INFO]: User Notch logged in with entity id 42

=== Crash Report ===
[13:52:00] [main/ERROR]: Encountered an unexpected exception
[13:52:00] [main/ERROR]: java.lang.NullPointerException: Cannot read field 'address' because 'connection' is null
[13:52:00] [main/ERROR]: at net.minecraft.server.network.ServerCommonPacketListenerImpl.m_9746_(SourceFile:123)
[13:52:01] [main/INFO]: --- BEGIN CRASH REPORT ---
[13:52:01] [main/INFO]: Time: 2024-01-15 13:52:01
[13:52:01] [main/INFO]: Description: Unexpected error
[13:52:01] [main/INFO]: Player with UUID 550e8400-e29b-41d4-a716-446655440000 caused crash
[13:52:01] [main/INFO]: C:\Users\Notch\AppData\Roaming\.minecraft\crash-reports\crash-2024-01-15-13.52.01.txt
[13:52:01] [main/INFO]: Client mods loaded: Sodium 0.5.8, Lithium 0.11.0
[13:52:01] [main/INFO]: Server mods loaded: Forge 47.3.0, NeoForge 21.1.0
[13:52:01] [main/INFO]: --- END CRASH REPORT ---

=== Debug / Verbose Logging ===
[13:53:00] [main/DEBUG]: Chunk loading at BlockPos(500, 60, -400)
[13:53:01] [main/TRACE]: Entity PlayerOne at Vec3d(100.5, 64.0, -200.25)
[13:53:02] [main/DEBUG]: Network handler for 192.168.1.100:25565
[13:53:03] [main/DEBUG]: clientId:"550e8400e29b41d4a716446655440000"
[13:53:04] [main/DEBUG]: session_id:"token:abcdef01-1234-5678-9abc-def012345678"
[13:53:05] [main/DEBUG]: xuid: 2535412345678901
[13:53:06] [main/DEBUG]: xboxUserId:"2535412345678902"
LOG;

    $output = applyPreFilters($input);

    expect($output)->toContain('Minecraft 1.21.1');
    expect($output)->toContain('Forge 47.3.0');
    expect($output)->toContain('NeoForge 21.1.0');
    expect($output)->toContain('Fabric Loader 0.15.11');
    expect($output)->toContain('sodium-0.5.8.jar');
    expect($output)->not->toContain('192.168.1.10');
    expect($output)->toContain('**.**.**.**');
    expect($output)->not->toContain('10.0.0.5');
    expect($output)->toContain('**.**.**.**');
    expect($output)->not->toContain('172.16.0.5');
    expect($output)->toContain('**.**.**.**');
    expect($output)->not->toContain('203.0.113.10');
    expect($output)->toContain('**.**.**.**');
    expect($output)->not->toContain('550e8400-e29b-41d4-a716-446655440000');
    expect($output)->toContain('********-****-****-****-************');
    expect($output)->not->toContain('abcdef01-1234-5678-9abc-def012345678');
    expect($output)->toContain('********-****-****-****-************');
    expect($output)->not->toContain('100.5, 64.0, -200.25');
    expect($output)->toContain('(*****, *****, *****)');
    expect($output)->not->toContain('200, 70, -300');
    expect($output)->toContain('****:****:****:****:****:****:****:****');
    expect($output)->toContain('**.**.**.**');
    expect($output)->toContain('token:********-****-****-****-************');
    expect($output)->toContain('accessToken:"********"');
    expect($output)->toContain('clientId:"****************************"');
    expect($output)->toContain('C:\Users\********\AppData');
    expect($output)->toContain('xuid:"****************"');
    expect($output)->toContain('xboxUserId:"****************"');
});

test('Filter chain masks sensitive data in Bukkit/Spigot/Paper plugin logs', function () {
    $input = <<<'LOG'
=== Bukkit/Spigot Server Startup ===
[13:00:00] [Server thread/INFO]: Starting minecraft server version 1.21.1
[13:00:00] [Server thread/INFO]: Loading 7 recipes
[13:00:00] [Server thread/INFO]: Loading 25 advancements
[13:00:01] [Server thread/INFO]: CraftBukkit version git-CraftBukkit-1.21.1-R0.1-SNAPSHOT
[13:00:01] [Server thread/INFO]: Server permissions file loaded
[13:00:02] [Server thread/INFO]: Server version 1.21.1-R0.1-SNAPSHOT

=== Plugin Loading (EssentialsX, WorldEdit, CoreProtect, Vault) ===
[13:00:03] [Server thread/INFO]: Loading EssentialsX v2.20.1.0
[13:00:03] [Server thread/INFO]: Loading WorldEdit version 7.2.15
[13:00:03] [Server thread/INFO]: Loading CoreProtect version 22.4
[13:00:04] [Server thread/INFO]: Loading Vault version 1.7.3
[13:00:04] [Server thread/INFO]: Loading LuckPerms version 5.4.102
[13:00:04] [Server thread/INFO]: Loading Multiverse-Core version 4.3.1
[13:00:05] [Server thread/INFO]: EssentialsX: Enabled 87 commands and 12 listeners
[13:00:05] [Server thread/INFO]: WorldEdit: Enabled schematic formatting
[13:00:06] [Server thread/INFO]: CoreProtect: Database connected successfully

=== Player Join/Quit (with IP logging) ===
[13:01:00] [Server thread/INFO]: Notch joined the game
[13:01:00] [Server thread/INFO]: Notch[/192.168.1.100:25565] logged in with entity id 42 at (100.5, 64.0, -200.25)
[13:01:05] [User Authenticator #1/INFO]: UUID of player Notch is 550e8400-e29b-41d4-a716-446655440000
[13:02:00] [Server thread/INFO]: Steve joined the game
[13:02:00] [Server thread/INFO]: Steve[/10.0.0.5:25565] logged in with entity id 43
[13:02:30] [Server thread/INFO]: Notch left the game
[13:03:00] [Server thread/INFO]: Jeb joined the game
[13:03:00] [Server thread/INFO]: Jeb[/172.16.0.5:25565] logged in

=== EssentialsX (homes, warps, kits) ===
[13:04:00] [Server thread/INFO]: Notch ran command: /home home
[13:04:01] [Server thread/INFO]: Teleported Notch to home at (200.5, 64.0, -300.25)
[13:04:30] [Server thread/INFO]: Steve ran command: /warp spawn
[13:04:31] [Server thread/INFO]: Teleported Steve to spawn at (0.5, 64.0, 0.0)
[13:05:00] [Server thread/INFO]: Notch ran command: /kit starter
[13:05:01] [Server thread/INFO]: Gave starter kit to Notch
[13:05:30] [Server thread/INFO]: Steve ran command: /tpa Notch
[13:05:31] [Server thread/INFO]: Sent tpa request from Steve to Notch
[13:05:32] [Server thread/INFO]: Session ID: token:550e8400-e29b-41d4-a716-446655440000
[13:05:33] [Server thread/INFO]: clientId:"550e8400e29b41d4a716446655440000"

=== WorldEdit (schematic loading, positions) ===
[13:06:00] [Server thread/INFO]: Player Notch performed //schem load castle
[13:06:01] [Server thread/INFO]: Loaded 5000 blocks from schematic
[13:06:02] [Server thread/INFO]: Player Notch performed //pos1 at (100, 64, -200)
[13:06:03] [Server thread/INFO]: Player Notch performed //pos2 at (200, 70, -300)
[13:06:04] [Server thread/INFO]: Created selection of 8000 blocks

=== CoreProtect (block logging, rollbacks) ===
[13:07:00] [Server thread/INFO]: CoreProtect: Player Notch placed stone at (150, 64, -250)
[13:07:01] [Server thread/INFO]: CoreProtect: Player Steve broke dirt at (151, 64, -251)
[13:07:02] [Server thread/INFO]: CoreProtect: Player Jeb placed oak_log at (152, 64, -252)
[13:07:30] [Server thread/INFO]: CoreProtect: Lookup for user Notch from (100, 64, -200) to (200, 70, -300)
[13:07:31] [Server thread/INFO]: CoreProtect: Rolled back 15 actions for user Steve

=== Vault/LuckPerms (permissions, groups) ===
[13:08:00] [Server thread/INFO]: LuckPerms: User Notch added to group default
[13:08:01] [Server thread/INFO]: LuckPerms: User Steve added to group moderator
[13:08:02] [Server thread/INFO]: LuckPerms: User Jeb added to group admin
[13:08:30] [Server thread/INFO]: Vault: Chat is hooked into LuckPerms
[13:08:31] [Server thread/INFO]: Vault: Economy is hooked into EssentialsX

=== Multiverse (world management) ===
[13:09:00] [Server thread/INFO]: Multiverse: Importing world world_nether
[13:09:01] [Server thread/INFO]: Multiverse: Created world world_the_end
[13:09:02] [Server thread/INFO]: Multiverse: Teleported Notch to world world_nether at (50.5, 60.0, -100.25)

=== Plugin App\Config/Data Paths ===
[13:10:00] [Server thread/INFO]: Loading config from plugins/Essentials/config.yml
[13:10:01] [Server thread/INFO]: Loading config from plugins/WorldEdit/schematics/castest.schem
[13:10:02] [Server thread/INFO]: Loading config from plugins/CoreProtect/database.db
[13:10:03] [Server thread/INFO]: Loading config from /home/paper/.local/share/paper/config/paper-global.yml
[13:10:04] [Server thread/INFO]: Loading config from C:\Users\Admin\Desktop\server\plugins\LuckPerms\config.yml

=== Ban/Kick/IP-related ===
[13:11:00] [Server thread/INFO]: BanManager: Banning player Hacker with IP 203.0.113.10
[13:11:01] [Server thread/INFO]: BanManager: IP ban added for 203.0.113.10
[13:11:02] [Server thread/INFO]: AdvancedBan: Player Hacker was banned by Admin
[13:11:03] [Server thread/INFO]: AdvancedBan: Reason: Griefing at (500, 60, -400)
[13:11:04] [Server thread/INFO]: Geyser: Player Notch connected with XUID 2535412345678901
[13:11:05] [Server thread/INFO]: Geyser: Player Steve has xboxUserId:2535412345678902

=== Plugin Errors ===
[13:12:00] [Server thread/ERROR]: Plugin 'FakePlugin' v1.0.0 encountered an error
[13:12:00] [Server thread/ERROR]: java.lang.NullPointerException: Cannot invoke method on null object
[13:12:00] [Server thread/ERROR]: at com.example.plugin.PlayerData.load(PlayerData.java:42)
[13:12:00] [Server thread/ERROR]: at com.example.plugin.Command.onCommand(Command.java:15)
[13:12:01] [Server thread/ERROR]: Could not pass event PlayerJoinEvent to FakePlugin v1.0.0
[13:12:02] [Server thread/WARN]: Disabling FakePlugin due to error
LOG;

    $output = applyPreFilters($input);

    expect($output)->toContain('CraftBukkit version git-CraftBukkit-1.21.1-R0.1-SNAPSHOT');
    expect($output)->toContain('EssentialsX v2.20.1.0');
    expect($output)->toContain('WorldEdit version 7.2.15');
    expect($output)->toContain('CoreProtect version 22.4');
    expect($output)->toContain('LuckPerms version 5.4.102');
    expect($output)->toContain('Multiverse-Core version 4.3.1');
    expect($output)->not->toContain('192.168.1.100');
    expect($output)->toContain('**.**.**.**');
    expect($output)->not->toContain('10.0.0.5');
    expect($output)->toContain('**.**.**.**');
    expect($output)->not->toContain('172.16.0.5');
    expect($output)->toContain('**.**.**.**');
    expect($output)->not->toContain('203.0.113.10');
    expect($output)->toContain('**.**.**.**');
    expect($output)->not->toContain('550e8400-e29b-41d4-a716-446655440000');
    expect($output)->toContain('********-****-****-****-************');
    expect($output)->not->toContain('100.5, 64.0, -200.25');
    expect($output)->toContain('(*****, *****, *****)');
    expect($output)->not->toContain('200, 70, -300');
    expect($output)->toContain('**.**.**.**');
    expect($output)->toContain('token:********-****-****-****-************');
    expect($output)->toContain('clientId:"****************************"');
    expect($output)->toContain('C:\Users\********\Desktop');
    expect($output)->toContain('XUID:"****************"');
    expect($output)->toContain('xboxUserId:"****************"');
    expect($output)->toContain('plugins/Essentials/config.yml');
    expect($output)->toContain('/home/********/.local/share/paper/config/paper-global.yml');
    expect($output)->toContain('Griefing at (*****, *****, *****)');
});