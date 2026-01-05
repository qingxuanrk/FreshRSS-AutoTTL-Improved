<?php

class StatItem
{
    public int $id;

    public string $name;

    public int $lastUpdate;

    public int $ttl;

    public int $avgTTL;

    private int $maxTTL;

    public function __construct(array $feed, int $maxTTL)
    {
        $this->id = (int) $feed['id'];
        $this->name = html_entity_decode($feed['name']);
        $this->lastUpdate = (int) $feed['lastUpdate'];
        $this->ttl = (int) $feed['ttl'];
        $this->avgTTL = (int) $feed['avgTTL'];
        $this->maxTTL = $maxTTL;
    }
}

class FeedUpdatePattern
{
    // 时间段模式（24 小时）
    public array $hourDensity = [];      // 每个时段的更新密度
    public array $hourInterval = [];     // 每个时段的平均间隔

    // 星期几模式（0=周日, 1=周一, ..., 6=周六）
    public array $dayOfWeekInterval = []; // 每个星期几的平均间隔 [0-6]
    public array $dayOfWeekHasUpdates = []; // 每个星期几是否有更新 [0-6]

    // 统计信息
    public int $totalEntries = 0;         // 总条目数
    public int $daysCovered = 0;          // 覆盖天数
    public int $lastAnalysisTime = 0;     // 上次分析时间

    // 降级标志
    public bool $hasEnoughData = false;    // 是否有足够的数据进行完整分析
    public float $simpleAvgInterval = 0;   // 简单平均间隔（用于降级）
}

class AutoTTLStats extends Minz_ModelPdo
{
    /**
     * @var int
     */
    private $defaultTTL;

    /**
     * @var int
     */
    private $maxTTL;

    /**
     * @var int
     */
    private $statsCount;

    /**
     * @var array<string, FeedUpdatePattern>
     */
    private static $patternCache = [];

    /**
     * @var array<string, int>
     */
    private static $ttlCache = [];

    /**
     * Cache expiration time (1 hour)
     */
    private const CACHE_EXPIRY = 3600;

    public function __construct(int $defaultTTL, int $maxTTL, int $statsCount)
    {
        parent::__construct();

        $this->defaultTTL = $defaultTTL;
        $this->maxTTL = $maxTTL;
        $this->statsCount = $statsCount;
    }

    /**
     * 获取 FreshRSS 的时区配置
     *
     * @return DateTimeZone|null 时区对象，如果无法获取则返回 null
     */
    private function getTimezone(): ?\DateTimeZone
    {
        try {
            // 尝试从 FreshRSS 用户配置获取时区
            if (class_exists('FreshRSS_Context')) {
                try {
                    $userConf = FreshRSS_Context::userConf();
                    if ($userConf !== null) {
                        $timezone = $userConf->timezone ?? null;
                        if ($timezone && $timezone !== '') {
                            return new \DateTimeZone($timezone);
                        }
                    }
                } catch (\Exception $e) {
                    // FreshRSS_Context::userConf() 可能抛出异常，继续使用默认时区
                }
            }
        } catch (\Exception $e) {
            // 如果时区无效，继续使用默认时区
        }

        // 如果无法获取 FreshRSS 时区，使用服务器默认时区
        try {
            $defaultTimezone = date_default_timezone_get();
            if ($defaultTimezone) {
                return new \DateTimeZone($defaultTimezone);
            }
        } catch (\Exception $e) {
            // 如果默认时区也无效，返回 null（将使用 UTC）
        }

        return null;
    }

    /**
     * 创建带时区的 DateTime 对象
     *
     * @param  int $timestamp Unix 时间戳
     * @return DateTime DateTime 对象
     */
    private function createDateTime(int $timestamp): \DateTime
    {
        $dt = new \DateTime('@' . $timestamp);
        $timezone = $this->getTimezone();
        if ($timezone !== null) {
            $dt->setTimezone($timezone);
        }
        return $dt;
    }

    public function calcAdjustedTTL(int $avgTTL): int
    {
        if ($this->defaultTTL > $this->maxTTL) {
            return $this->defaultTTL;
        }

        if ($avgTTL === 0 || $avgTTL > $this->maxTTL) {
            return $this->maxTTL;
        } elseif ($avgTTL < $this->defaultTTL) {
            return $this->defaultTTL;
        }

        return $avgTTL;
    }

    public function getAdjustedTTL(int $feedID): int
    {
        // 使用新的动态 TTL 计算
        return $this->getDynamicTTL($feedID);
    }

    /**
     * 根据当前时间动态计算 TTL
     *
     * @param  int      $feedID      Feed ID
     * @param  int|null $currentTime 当前时间戳，null 则使用当前时间
     * @return int 计算得到的 TTL（秒）
     */
    public function getDynamicTTL(int $feedID, ?int $currentTime = null): int
    {
        if ($currentTime === null) {
            $currentTime = time();
        }

        // 检查缓存
        $cacheKey = "ttl_{$feedID}";
        if (isset(self::$ttlCache[$cacheKey])) {
            $cached = self::$ttlCache[$cacheKey];
            // 缓存有效期 5 分钟（比模式缓存短，因为 TTL 需要更频繁更新）
            if (($currentTime - $cached['time']) < 300) {
                return $cached['ttl'];
            }
        }

        // 获取或分析 feed 的更新模式
        $pattern = $this->analyzeFeedPattern($feedID);

        // 根据模式计算动态 TTL
        $dynamicTTL = $this->_calculateTTLFromPattern($pattern, $currentTime);

        // 平滑处理
        $previousTTL = $this->_getCachedTTL($feedID);
        if ($previousTTL > 0) {
            // 平滑处理：新值 = 旧值 * 0.7 + 新值 * 0.3
            $dynamicTTL = (int)($previousTTL * 0.7 + $dynamicTTL * 0.3);
        }

        // 边界约束
        $dynamicTTL = $this->calcAdjustedTTL($dynamicTTL);

        // 更新缓存
        self::$ttlCache[$cacheKey] = [
            'ttl' => $dynamicTTL,
            'time' => $currentTime,
        ];

        return $dynamicTTL;
    }

    /**
     * 分析 feed 的更新模式
     *
     * @param  int $feedID Feed ID
     * @return FeedUpdatePattern
     */
    public function analyzeFeedPattern(int $feedID): FeedUpdatePattern
    {
        $cacheKey = "pattern_{$feedID}";
        $currentTime = time();

        // 检查缓存
        if (isset(self::$patternCache[$cacheKey])) {
            $cached = self::$patternCache[$cacheKey];
            // 缓存有效期 1 小时
            if (($currentTime - $cached->lastAnalysisTime) < self::CACHE_EXPIRY) {
                return $cached;
            }
        }

        // 创建新模式对象
        $pattern = new FeedUpdatePattern();
        $pattern->lastAnalysisTime = $currentTime;

        // 获取原始数据（使用 SQLite 兼容的查询）
        $cutoff = $this->getStatsCutoff();
        $sql = <<<SQL
SELECT date
FROM `_entry`
WHERE id_feed = {$feedID} AND date > {$cutoff}
ORDER BY date ASC
SQL;

        $stm = $this->pdo->query($sql);
        $entries = $stm->fetchAll(PDO::FETCH_COLUMN);

        $pattern->totalEntries = count($entries);

        // 数据不足，使用降级策略
        if ($pattern->totalEntries < 10) {
            $pattern->hasEnoughData = false;
            if ($pattern->totalEntries > 0) {
                // 计算简单平均间隔
                $firstDate = (int)$entries[0];
                $lastDate = (int)$entries[$pattern->totalEntries - 1];
                $pattern->simpleAvgInterval = ($lastDate - $firstDate) / max(1, $pattern->totalEntries - 1);
            }
            self::$patternCache[$cacheKey] = $pattern;
            return $pattern;
        }

        // PHP 端处理时间分析
        $hourStats = [];
        $dayStats = []; // 按星期几（0-6）分别统计
        $uniqueDates = [];

        // 初始化星期几统计数组
        for ($dow = 0; $dow <= 6; $dow++) {
            $dayStats[$dow] = [];
            $pattern->dayOfWeekInterval[$dow] = 0;
            $pattern->dayOfWeekHasUpdates[$dow] = false;
        }

        foreach ($entries as $i => $timestamp) {
            $timestamp = (int)$timestamp;
            $dt = $this->createDateTime($timestamp);
            $hour = (int)$dt->format('G');  // 0-23
            $dayOfWeek = (int)$dt->format('w');  // 0=Sunday, 6=Saturday
            $dateStr = $dt->format('Y-m-d');

            // 记录唯一日期
            if (!in_array($dateStr, $uniqueDates, true)) {
                $uniqueDates[] = $dateStr;
            }

            // 初始化小时统计
            if (!isset($hourStats[$hour])) {
                $hourStats[$hour] = ['count' => 0, 'intervals' => []];
            }
            $hourStats[$hour]['count']++;

            // 计算间隔（如果有下一个条目）
            if (isset($entries[$i + 1])) {
                $interval = (int)$entries[$i + 1] - $timestamp;
                if ($interval > 0) {
                    $hourStats[$hour]['intervals'][] = $interval;

                    // 按星期几分类（0=周日, 1=周一, ..., 6=周六）
                    $dayStats[$dayOfWeek][] = $interval;
                }
            }
        }

        $pattern->daysCovered = count($uniqueDates);
        $pattern->hasEnoughData = ($pattern->totalEntries >= 30 && $pattern->daysCovered >= 7);

        // 计算小时平均间隔和密度
        foreach ($hourStats as $hour => &$stats) {
            if (count($stats['intervals']) > 0) {
                $pattern->hourInterval[$hour] = array_sum($stats['intervals']) / count($stats['intervals']);
            } else {
                $pattern->hourInterval[$hour] = 0;
            }

            // 计算密度（更新次数 / 该时段在统计周期内的出现次数）
            $pattern->hourDensity[$hour] = $stats['count'] / max(1, $pattern->daysCovered);
        }

        // 计算每个星期几的平均间隔（基于实际数据）
        for ($dow = 0; $dow <= 6; $dow++) {
            if (count($dayStats[$dow]) > 0) {
                $pattern->dayOfWeekInterval[$dow] = array_sum($dayStats[$dow]) / count($dayStats[$dow]);
                $pattern->dayOfWeekHasUpdates[$dow] = true;
            }
        }

        // 计算简单平均间隔（用于降级）
        if ($pattern->totalEntries > 1) {
            $firstDate = (int)$entries[0];
            $lastDate = (int)$entries[$pattern->totalEntries - 1];
            $pattern->simpleAvgInterval = ($lastDate - $firstDate) / ($pattern->totalEntries - 1);
        }

        // 更新缓存
        self::$patternCache[$cacheKey] = $pattern;

        return $pattern;
    }

    /**
     * 根据模式计算 TTL
     *
     * @param  FeedUpdatePattern $pattern     更新模式
     * @param  int              $currentTime 当前时间戳
     * @return int TTL 值（秒）
     */
    private function _calculateTTLFromPattern(FeedUpdatePattern $pattern, int $currentTime): int
    {
        // 数据不足，使用简单平均
        if (!$pattern->hasEnoughData) {
            if ($pattern->simpleAvgInterval > 0) {
                return (int)$pattern->simpleAvgInterval;
            }
            return $this->maxTTL;
        }

        $dt = $this->createDateTime($currentTime);
        $currentHour = (int)$dt->format('G');
        $dayOfWeek = (int)$dt->format('w');  // 0=Sunday, 1=Monday, ..., 6=Saturday

        // 获取当前时段的平均间隔
        $baseInterval = $pattern->hourInterval[$currentHour] ?? 0;

        // 如果当前时段没有数据，使用所有时段的平均值
        if ($baseInterval <= 0) {
            $validIntervals = array_filter($pattern->hourInterval);
            if (count($validIntervals) > 0) {
                $baseInterval = array_sum($validIntervals) / count($validIntervals);
            } else {
                $baseInterval = $pattern->simpleAvgInterval;
            }
        }

        // 根据当前是星期几，使用对应的平均间隔（基于实际数据）
        if (isset($pattern->dayOfWeekHasUpdates[$dayOfWeek])
            && $pattern->dayOfWeekHasUpdates[$dayOfWeek]
            && isset($pattern->dayOfWeekInterval[$dayOfWeek])
            && $pattern->dayOfWeekInterval[$dayOfWeek] > 0
        ) {
            // 如果当前星期几有实际数据，使用该星期几的平均间隔
            $baseInterval = $pattern->dayOfWeekInterval[$dayOfWeek];
        } else {
            // 如果当前星期几没有数据，使用有数据的其他星期几的平均值
            $validDayIntervals = array_filter(
                $pattern->dayOfWeekInterval,
                function ($interval, $dow) use ($pattern) {
                    return $interval > 0 && $pattern->dayOfWeekHasUpdates[$dow];
                },
                ARRAY_FILTER_USE_BOTH
            );

            if (count($validDayIntervals) > 0) {
                $baseInterval = array_sum($validDayIntervals) / count($validDayIntervals);
            }
            // 如果所有星期几都没有数据，保持使用小时间隔或简单平均间隔
        }

        // 小时密度加权
        $hourWeight = 1.0;
        if (isset($pattern->hourDensity[$currentHour])
            && $pattern->hourDensity[$currentHour] > 0
        ) {
            // 计算平均密度
            $validDensities = array_filter($pattern->hourDensity);
            $avgDensity = array_sum($validDensities) / max(1, count($validDensities));
            if ($avgDensity > 0) {
                // 密度越高，权重越小（更新更频繁，TTL 更短）
                $hourWeight = $avgDensity / max(0.1, $pattern->hourDensity[$currentHour]);
                // 限制权重范围在 0.5 到 2.0 之间
                $hourWeight = max(0.5, min(2.0, $hourWeight));
            }
        }

        // 计算动态 TTL（基于实际数据，不再需要 dayAdjustment）
        $dynamicTTL = $baseInterval * $hourWeight;

        return (int)max($this->defaultTTL, $dynamicTTL);
    }

    /**
     * 获取缓存的 TTL
     *
     * @param  int $feedID Feed ID
     * @return int 缓存的 TTL，如果没有则返回 0
     */
    private function _getCachedTTL(int $feedID): int
    {
        $cacheKey = "ttl_{$feedID}";
        if (isset(self::$ttlCache[$cacheKey])) {
            return self::$ttlCache[$cacheKey]['ttl'];
        }
        return 0;
    }

    /**
     * 清除指定 feed 的缓存
     *
     * @param  int $feedID Feed ID
     * @return void
     */
    public function clearCache(int $feedID): void
    {
        $patternKey = "pattern_{$feedID}";
        $ttlKey = "ttl_{$feedID}";
        unset(self::$patternCache[$patternKey]);
        unset(self::$ttlCache[$ttlKey]);
    }

    public function getFeedStats(bool $usesAutoTTL): array
    {
        $where = '';
        if ($usesAutoTTL) {
            $where = 'feed.ttl = 0';
        } else {
            $where = 'feed.ttl != 0';
        }

        $sql = <<<SQL
SELECT
    feed.id,
    feed.name,
    feed.`lastUpdate`,
    feed.ttl,
    COALESCE((MAX(stats.date) - MIN(stats.date)) / COUNT(1), 0) AS `avgTTL`
FROM `_feed` AS feed
LEFT JOIN (
    SELECT id_feed, date
    FROM `_entry`
    WHERE date > {$this->getStatsCutoff()}
) AS stats ON feed.id = stats.id_feed
WHERE {$where}
GROUP BY feed.id
ORDER BY COALESCE((MAX(stats.date) - MIN(stats.date)) / COUNT(1), 0) = 0, `avgTTL` ASC
LIMIT {$this->statsCount}
SQL;

        $stm = $this->pdo->query($sql);
        $res = $stm->fetchAll(PDO::FETCH_NAMED);

        $list = [];
        foreach ($res as $feed) {
            $list[] = new StatItem($feed, $this->maxTTL);
        }

        return $list;
    }

    /**
     * 获取 feed 的更新模式信息（用于展示）
     *
     * @param  int $feedID Feed ID
     * @return FeedUpdatePattern|null
     */
    public function getFeedPattern(int $feedID): ?FeedUpdatePattern
    {
        try {
            return $this->analyzeFeedPattern($feedID);
        } catch (Exception $e) {
            if (class_exists('Minz_Log')) {
                Minz_Log::warning(
                    "AutoTTL: Failed to analyze pattern for feed {$feedID}: " . $e->getMessage()
                );
            }
            return null;
        }
    }

    /**
     * 获取最活跃的时段（用于展示）
     *
     * @param  FeedUpdatePattern $pattern 更新模式
     * @return array 最活跃的时段信息 [hour, density]
     */
    public function getMostActiveHours(FeedUpdatePattern $pattern): array
    {
        if (empty($pattern->hourDensity)) {
            return [];
        }

        // 按密度排序
        arsort($pattern->hourDensity);
        $topHours = array_slice($pattern->hourDensity, 0, 3, true);

        $result = [];
        foreach ($topHours as $hour => $density) {
            $result[] = [
                'hour' => $hour,
                'density' => $density,
            ];
        }

        return $result;
    }

    private function getStatsCutoff(): int
    {
        // Get entry stats from last 30 days only
        // so we don't depend on old entries and purge policy so much.
        return time() - 30 * 24 * 60 * 60;
    }

    public function humanIntervalFromSeconds(int $seconds): string
    {
        $from = new \DateTime('@0');
        $to = new \DateTime("@$seconds");
        $interval = $from->diff($to);

        $results = [];

        if ($interval->y === 1) {
            $results[] = "{$interval->y} y";
        } elseif ($interval->y > 1) {
            $results[] = "{$interval->y} y";
        }

        if ($interval->m === 1) {
            $results[] = "{$interval->m} month";
        } elseif ($interval->m > 1) {
            $results[] = "{$interval->m} months";
        }

        if ($interval->d === 1) {
            $results[] = "{$interval->d} d";
        } elseif ($interval->d > 1) {
            $results[] = "{$interval->d} d";
        }

        if ($interval->h === 1) {
            $results[] = "{$interval->h} h";
        } elseif ($interval->h > 1) {
            $results[] = "{$interval->h} h";
        }

        if ($interval->i === 1) {
            $results[] = "{$interval->i} min";
        } elseif ($interval->i > 1) {
            $results[] = "{$interval->i} min";
        } elseif ($interval->i === 0 && $interval->s === 1) {
            $results[] = "{$interval->s} sec";
        } elseif ($interval->i === 0 && $interval->s > 1) {
            $results[] = "{$interval->s} sec";
        }

        return implode(' ', $results);
    }
}
