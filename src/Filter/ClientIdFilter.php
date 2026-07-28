<?php

namespace Filter;

use Filter\Pattern\PatternWithReplacement;

class ClientIdFilter extends Filter
{
    /**
     * @return PatternWithReplacement[]
     */
    protected static function getPatterns(): array
    {
        return [
            new PatternWithReplacement(
                'clientId["\s:=]+[a-zA-Z0-9+\/=_-]{20,}"',
                'clientId:"********"'
            ),
            new PatternWithReplacement(
                'client_id["\s:=]+[a-zA-Z0-9+\/=_-]{20,}"',
                'client_id:"********"'
            ),
            new PatternWithReplacement(
                'deviceId["\s:=]+[a-zA-Z0-9+\/=_-]{20,}"',
                'deviceId:"********"'
            ),
            new PatternWithReplacement(
                'device_id["\s:=]+[a-zA-Z0-9+\/=_-]{20,}"',
                'device_id:"********"'
            ),
            new PatternWithReplacement(
                'instanceId["\s:=]+[a-zA-Z0-9+\/=_-]{20,}"',
                'instanceId:"********"'
            ),
            new PatternWithReplacement(
                'instance_id["\s:=]+[a-zA-Z0-9+\/=_-]{20,}"',
                'instance_id:"********"'
            ),
            new PatternWithReplacement(
                'launcherId["\s:=]+[a-zA-Z0-9+\/=_-]{20,}"',
                'launcherId:"********"'
            ),
            new PatternWithReplacement(
                'launcher_id["\s:=]+[a-zA-Z0-9+\/=_-]{20,}"',
                'launcher_id:"********"'
            ),
            new PatternWithReplacement(
                '--clientId\s[a-zA-Z0-9+\/=_-]{20,}',
                '--clientId ********'
            ),
            new PatternWithReplacement(
                '--client_id\s[a-zA-Z0-9+\/=_-]{20,}',
                '--client_id ********'
            ),
            new PatternWithReplacement(
                '--deviceId\s[a-zA-Z0-9+\/=_-]{20,}',
                '--deviceId ********'
            ),
            new PatternWithReplacement(
                '--device_id\s[a-zA-Z0-9+\/=_-]{20,}',
                '--device_id ********'
            ),
        ];
    }

    public static function filter(string $data): string
    {
        foreach (static::getPatterns() as $pattern) {
            $data = static::safePregReplace('/' . $pattern->getPattern() . '/', $pattern->getReplacement(), $data);
        }
        return $data;
    }
}