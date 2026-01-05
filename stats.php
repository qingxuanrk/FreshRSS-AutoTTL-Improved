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

    // 工作日/周末模式
    public float $weekdayInterval = 0;    // 工作日平均间隔
    public float $weekendInterval = 0;     // 周末平均间隔
    public bool $hasWeekendUpdates = false; // 周末是否有更新

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
        $dayStats = ['weekday' => [], 'weekend' => []];
        $uniqueDates = [];

        foreach ($entries as $i => $timestamp) {
            $timestamp = (int)$timestamp;
            $dt = new DateTime('@' . $timestamp);
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

                    // 按工作日/周末分类
                    $isWeekend = ($dayOfWeek == 0 || $dayOfWeek == 6);
                    $key = $isWeekend ? 'weekend' : 'weekday';
                    $dayStats[$key][] = $interval;
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

        // 计算工作日/周末平均间隔
        if (count($dayStats['weekday']) > 0) {
            $pattern->weekdayInterval = array_sum($dayStats['weekday']) / count($dayStats['weekday']);
        }
        if (count($dayStats['weekend']) > 0) {
            $pattern->weekendInterval = array_sum($dayStats['weekend']) / count($dayStats['weekend']);
            $pattern->hasWeekendUpdates = true;
        }

        // 如果没有周末数据，使用工作日数据作为默认值
        if (!$pattern->hasWeekendUpdates && $pattern->weekdayInterval > 0) {
            $pattern->weekendInterval = $pattern->weekdayInterval * 2; // 周末假设更新频率减半
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

        $dt = new DateTime('@' . $currentTime);
        $currentHour = (int)$dt->format('G');
        $dayOfWeek = (int)$dt->format('w');
        $isWeekend = ($dayOfWeek == 0 || $dayOfWeek == 6);

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

        // 工作日/周末调整
        $dayAdjustment = 0;
        if ($isWeekend) {
            if ($pattern->hasWeekendUpdates && $pattern->weekendInterval > 0) {
                // 使用周末间隔
                $baseInterval = $pattern->weekendInterval;
            } else {
                // 周末不更新，延长 TTL
                $dayAdjustment = 0.5; // 增加 50%
            }
        } else {
            if ($pattern->weekdayInterval > 0) {
                // 使用工作日间隔
                $baseInterval = $pattern->weekdayInterval;
            }
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

        // 计算动态 TTL
        $dynamicTTL = $baseInterval * (1 + $dayAdjustment) * $hourWeight;

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
            $results[] = "{$interval->y} year";
        } elseif ($interval->y > 1) {
            $results[] = "{$interval->y} years";
        }

        if ($interval->m === 1) {
            $results[] = "{$interval->m} month";
        } elseif ($interval->m > 1) {
            $results[] = "{$interval->m} months";
        }

        if ($interval->d === 1) {
            $results[] = "{$interval->d} day";
        } elseif ($interval->d > 1) {
            $results[] = "{$interval->d} days";
        }

        if ($interval->h === 1) {
            $results[] = "{$interval->h} hour";
        } elseif ($interval->h > 1) {
            $results[] = "{$interval->h} hours";
        }

        if ($interval->i === 1) {
            $results[] = "{$interval->i} minute";
        } elseif ($interval->i > 1) {
            $results[] = "{$interval->i} minutes";
        } elseif ($interval->i === 0 && $interval->s === 1) {
            $results[] = "{$interval->s} second";
        } elseif ($interval->i === 0 && $interval->s > 1) {
            $results[] = "{$interval->s} seconds";
        }

        return implode(' ', $results);
    }
}
