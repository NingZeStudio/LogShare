<?php

use Filter\Filter;
use Filter\IPv4Filter;
use Filter\IPv6Filter;
use Filter\IPv6ShortFilter;
use Filter\UuidFilter;
use Filter\XuidFilter;
use Filter\SessionTokenFilter;
use Filter\ClientIdFilter;
use Filter\CoordinateFilter;
use Filter\UsernameFilter;
use Filter\AccessTokenFilter;

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

test('Filter chain applies all filters in order', function () {
    $input = 'Player Steve[/192.168.1.1:25565] UUID: 550e8400-e29b-41d4-a716-446655440000 at (100, 64, -200)';
    $output = Filter::filterAll($input);
    
    expect($output)->not->toContain('192.168.1.1');
    expect($output)->toContain('**.**.**.**');
    expect($output)->not->toContain('550e8400-e29b-41d4-a716-446655440000');
    expect($output)->toContain('********-****-****-****-************');
    expect($output)->not->toContain('(100, 64, -200)');
    expect($output)->toContain('(*****, *****, *****)');
});