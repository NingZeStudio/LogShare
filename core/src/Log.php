<?php

use Aternos\Codex\Analysis\Analysis;
use Aternos\Codex\Analysis\Information;
use Aternos\Codex\Log\File\StringLogFile;
use Aternos\Codex\Log\Level;
use Aternos\Codex\Minecraft\Analysis\Information\Vanilla\VanillaVersionInformation;
use Aternos\Codex\Minecraft\Log\Minecraft\Vanilla\Fabric\FabricLog;
use Aternos\Codex\Minecraft\Log\Minecraft\Vanilla\VanillaClientLog;
use Aternos\Codex\Minecraft\Log\Minecraft\Vanilla\VanillaCrashReportLog;
use Aternos\Codex\Minecraft\Log\Minecraft\Vanilla\VanillaNetworkProtocolErrorReportLog;
use Aternos\Codex\Minecraft\Log\Minecraft\Vanilla\VanillaServerLog;
use Aternos\Sherlock\MapLocator\FabricMavenMapLocator;
use Aternos\Sherlock\MapLocator\LauncherMetaMapLocator;
use Aternos\Sherlock\Maps\GZURLYarnMap;
use Aternos\Sherlock\Maps\ObfuscationMap;
use Aternos\Sherlock\Maps\URLVanillaObfuscationMap;
use Aternos\Sherlock\Maps\VanillaObfuscationMap;
use Aternos\Sherlock\Maps\YarnMap;
use Aternos\Sherlock\ObfuscatedString;
use Cache\CacheEntry;
use Data\MetadataEntry;
use Data\Token;
use Filter\Filter;
use Printer\Printer;
use Storage\StorageInterface;

class Log
{
    private bool $exists = false;
    private ?Id $id = null;
    private ?string $data = null;
    private ?Token $token = null;
    private array $metadata = [];
    private ?string $source = null;
    private ?int $created = null;
    private ?int $expires = null;
    protected \Aternos\Codex\Log\Log $log;
    protected ?ObfuscatedString $obfuscatedContent = null;

    /**
     * @var Analysis
     */
    protected Analysis $analysis;

    /**
     * @var Printer
     */
    protected Printer $printer;

    /**
     * Log constructor.
     *
     * @param Id|null $id
     */
    public function __construct(?Id $id = null)
    {
        if ($id) {
            $this->id = $id;
            $this->load();
        }
    }

    /**
     * Load the log from storage
     */
    private function load()
    {
        $config = Config::Get('storage');

        if (!isset($config['storages'][$this->id->getStorage()])) {
            $this->exists = false;
            return;
        }

        if (!$config['storages'][$this->id->getStorage()]['enabled']) {
            $this->exists = false;
            return;
        }

        /**
         * @var StorageInterface $storage
         */
        $storage = $config['storages'][$this->id->getStorage()]['class'];

        $result = $storage::Get($this->id);

        if ($result === null) {
            $this->exists = false;
            return;
        }

        $this->data = $result['data'];
        $this->token = isset($result['token']) ? new Token($result['token']) : null;
        $this->metadata = MetadataEntry::allFromArray($result['metadata'] ?? []);
        $this->source = $result['source'] ?? null;
        $this->created = $result['created']?->toDateTime()->getTimestamp() ?? null;
        $this->expires = $result['expires']?->toDateTime()->getTimestamp() ?? null;
        $this->exists = true;

        $this->analyse();
        $this->printer = (new Printer())->setLog($this->log)->setId($this->id);
    }

    /**
     * Analyse the log content
     * @return Analysis
     */
    public function analyse(): Analysis
    {
        $this->log = (new Detective())->setLogFile(new StringLogFile($this->data))->detect();
        $this->log->parse();
        $this->analysis = $this->log->analyse();
        $this->deobfuscateContent();
        return $this->analysis;
    }

    /**
     * get the obfuscation map matching this log
     * @param $version
     * @return ObfuscationMap|null
     */
    protected function getObfuscationMap($version): ?ObfuscationMap
    {
        if (in_array(get_class($this->get()), [
            VanillaServerLog::class,
            VanillaClientLog::class,
            VanillaCrashReportLog::class,
            VanillaNetworkProtocolErrorReportLog::class
        ])){
            $urlCache = new CacheEntry("sherlock:vanilla:$version:client");

            $mapURL = $urlCache->get();
            if (!$mapURL) {
                $mapURL = (new LauncherMetaMapLocator($version, "client"))->findMappingURL();

                if (!$mapURL) {
                    return null;
                }

                $urlCache->set($mapURL, 30 * 24 * 60 * 60);
            }

            try {
                $mapCache = new CacheEntry("sherlock:$mapURL");
                if ($mapContent = $mapCache->get()) {
                    $map = new VanillaObfuscationMap($mapContent);
                } else {
                    $map = new URLVanillaObfuscationMap($mapURL);
                    $mapCache->set($map->getContent());
                }
            } catch (Exception) {
            }
            return $map ?? null;
        }

        if ($this->get() instanceof FabricLog) {
            $urlCache = new CacheEntry("sherlock:yarn:$version:server");

            $mapURL = $urlCache->get();
            if (!$mapURL) {
                $mapURL = (new FabricMavenMapLocator($version))->findMappingURL();

                if (!$mapURL) {
                    return null;
                }

                $urlCache->set($mapURL, 24 * 60 * 60);
            }

            try {
                $mapCache = new CacheEntry("sherlock:$mapURL");
                if ($mapContent = $mapCache->get()) {
                    $map = new YarnMap($mapContent);
                } else {
                    $map = new GZURLYarnMap($mapURL);
                    $mapCache->set($map->getContent());
                }
            } catch (Exception) {
            }
            return $map ?? null;
        }

        return null;
    }

    /**
     * deobfuscate the content of this log
     * @return void
     */
    protected function deobfuscateContent()
    {
        /**
         * @var ?Information $version
         */
        $version = $this->analysis->getFilteredInsights(VanillaVersionInformation::class)[0] ?? null;
        if (!$version) {
            return;
        }
        $version = $version->getValue();

        try {
            $map = $this->getObfuscationMap($version);
        } catch (\Exception) {
            $map = null;
        }

        if ($map === null) {
            return;
        }

        $this->obfuscatedContent = new ObfuscatedString($this->data, $map);
        if ($content = $this->obfuscatedContent->getMappedContent()) {
            $this->data = $content;
            $this->log = (new Detective())->setLogFile(new StringLogFile($this->data))->detect();
            $this->log->parse();
        }
    }

    /**
     * Checks if the log exists
     *
     * @return bool
     */
    public function exists(): bool
    {
        return $this->exists;
    }

    /**
     * Get the log
     *
     * @return \Aternos\Codex\Log\Log
     */
    public function get(): \Aternos\Codex\Log\Log
    {
        return $this->log;
    }

    /**
     * Get the log analysis
     *
     * @return Analysis
     */
    public function getAnalysis(): Analysis
    {
        return $this->analysis;
    }

    /**
     * @return Printer
     */
    public function getPrinter(): Printer
    {
        return $this->printer;
    }

    /**
     * Get the amount of lines in this log
     *
     * @return int
     */
    public function getLineNumbers(): int
    {
        return count(explode("\n", $this->data));
    }

    /**
     * Get the amount of error entries in the log
     *
     * @return int
     */
    public function getErrorCount(): int
    {
        $errorCount = 0;

        foreach ($this->log as $entry) {
            if ($entry->getLevel()->asInt() <= Level::ERROR->asInt()) {
                $errorCount++;
            }
        }

        return $errorCount;
    }

    /**
     * Get the raw content of the log
     *
     * @return string
     */
    public function getContent(): string
    {
        return $this->data;
    }

    /**
     * Set the data of the log without saving it to storage
     *
     * @param string $data
     * @return Log
     */
    public function setData(string $data): Log
    {
        $this->data = $data;
        $this->preFilter();
        return $this;
    }

    /**
     * Put data into the log
     *
     * @param string $data
     * @param Token|null $token
     * @param MetadataEntry[] $metadata
     * @param string|null $source
     * @return ?Id
     */
    public function put(string $data, ?Token $token = null, array $metadata = [], ?string $source = null): ?Id
    {
        $this->data = $data;
        $this->preFilter();
        $this->token = $token ?? new Token();
        $this->metadata = $metadata;
        $this->source = $source;

        $config = Config::Get('storage');

        /**
         * @var StorageInterface $storage
         */
        $storage = $config['storages'][$config['storageId']]['class'];

        $this->id = $storage::Put($this->data, $this->token, $this->metadata, $this->source);
        $this->exists = true;

        return $this->id;
    }

    /**
     * Renew the expiry timestamp to expand the ttl
     */
    public function renew()
    {
        $config = Config::Get('storage');

        /**
         * @var StorageInterface $storage
         */
        $storage = $config['storages'][$this->id->getStorage()]['class'];

        $storage::Renew($this->id);
        
        // Update local expires value
        $this->expires = time() + $config['storageTime'];
    }

    /**
     * Apply pre filters to data string
     */
    private function preFilter()
    {
        $config = Config::Get('filter');
        foreach ($config['pre'] as $preFilterClass) {
            $this->data = $preFilterClass::filter($this->data);
        }
    }

    /**
     * Delete the log from storage
     *
     * @return bool Success
     */
    public function delete(): bool
    {
        if (!$this->id) {
            return false;
        }

        $config = Config::Get('storage');

        if (!isset($config['storages'][$this->id->getStorage()])) {
            return false;
        }

        if (!$config['storages'][$this->id->getStorage()]['enabled']) {
            return false;
        }

        /**
         * @var StorageInterface $storage
         */
        $storage = $config['storages'][$this->id->getStorage()]['class'];

        $result = $storage::Delete($this->id);

        if ($result) {
            $this->exists = false;
            $this->data = null;
        }

        return $result;
    }

    /**
     * Get the token
     *
     * @return Token|null
     */
    public function getToken(): ?Token
    {
        return $this->token;
    }

    /**
     * Get the metadata
     *
     * @return MetadataEntry[]
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Get the source
     *
     * @return string|null
     */
    public function getSource(): ?string
    {
        return $this->source;
    }

    /**
     * Get the created timestamp
     *
     * @return int|null
     */
    public function getCreated(): ?int
    {
        return $this->created;
    }

    /**
     * Get the expires timestamp
     *
     * @return int|null
     */
    public function getExpires(): ?int
    {
        return $this->expires;
    }

    /**
     * Get the size of the log content
     *
     * @return int
     */
    public function getSize(): int
    {
        return strlen($this->data);
    }

    /**
     * Verify if the provided token matches
     *
     * @param string $token
     * @return bool
     */
    public function verifyToken(string $token): bool
    {
        if (!$this->token) {
            return false;
        }
        return $this->token->matches($token);
    }
}
