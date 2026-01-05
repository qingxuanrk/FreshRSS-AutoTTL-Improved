# FreshRSS AutoTTL 智能优化方案

## 一、现状分析

### 当前实现的问题

1. **过于简单的平均计算**
   - 当前仅使用 `(MAX(date) - MIN(date)) / COUNT(1)` 计算平均更新间隔
   - 忽略了更新时间的分布特征
   - 无法适应不同时间段的更新模式差异

2. **缺乏时间维度分析**
   - 未考虑一天中不同时间段的更新频率差异
   - 未考虑工作日与周末的更新模式差异
   - 未考虑更新间隔的动态变化

3. **静态 TTL 计算**
   - TTL 值一旦计算，在下次更新前保持不变
   - 无法根据当前时间动态调整

## 二、优化目标

通过分析以下三个核心特征，实现动态智能的 TTL 计算：

1. **更新时间集中的时间段**：识别 feed 在一天中的活跃更新时段
2. **更新间隔的动态差异**：分析不同时间段的更新间隔变化
3. **周一到周日的更新特征**：区分工作日和周末的更新模式

## 三、核心算法设计

### 3.1 时间段分析（Hour-based Analysis）

**目标**：识别 feed 在一天中哪些时间段更新最频繁

**实现思路**：
- 将一天分为 24 个时段（0-23 点）
- 统计每个时段的历史更新次数
- 计算每个时段的更新密度（更新次数 / 该时段在统计周期内的出现次数）
- 识别"活跃时段"（更新密度高于平均值的时段）

**SQLite 兼容的 SQL 查询**：
```sql
SELECT 
    CAST(strftime('%H', datetime(date, 'unixepoch')) AS INTEGER) AS hour,
    COUNT(*) AS update_count,
    CAST(COUNT(*) AS REAL) / COUNT(DISTINCT date(datetime(date, 'unixepoch'))) AS density
FROM `_entry`
WHERE id_feed = {feedID} AND date > {cutoff}
GROUP BY hour
ORDER BY density DESC
```

**说明**：
- `datetime(date, 'unixepoch')`：将 Unix 时间戳转换为 SQLite 日期时间格式
- `strftime('%H', ...)`：提取小时（0-23）
- `date(...)`：提取日期部分用于去重统计

### 3.2 更新间隔动态差异分析（Interval Variance Analysis）

**目标**：分析不同时间段的平均更新间隔，识别更新间隔的变化模式

**实现思路**：
- 计算相邻条目之间的时间间隔
- 按时间段分组统计平均间隔
- 识别间隔较短（更新频繁）的时段和间隔较长（更新稀疏）的时段
- 根据当前时间所在时段，选择对应的平均间隔

**SQLite 兼容的 SQL 查询**：
```sql
SELECT 
    CAST(strftime('%H', datetime(e1.date, 'unixepoch')) AS INTEGER) AS hour,
    AVG(e2.date - e1.date) AS avg_interval,
    COUNT(*) AS sample_count
FROM `_entry` e1
INNER JOIN `_entry` e2 ON e1.id_feed = e2.id_feed 
    AND e2.date = (
        SELECT MIN(date) 
        FROM `_entry` 
        WHERE id_feed = e1.id_feed AND date > e1.date
    )
WHERE e1.id_feed = {feedID} 
    AND e1.date > {cutoff}
GROUP BY hour
HAVING sample_count >= 2
```

### 3.3 工作日/周末特征分析（Day-of-Week Analysis）

**目标**：区分工作日（周一到周五）和周末（周六、周日）的更新模式

**实现思路**：
- 统计工作日的平均更新间隔
- 统计周末的平均更新间隔
- 根据当前是工作日还是周末，选择对应的更新间隔
- 对于周末不更新的 feed，周末时使用更长的 TTL

**SQLite 兼容的 SQL 查询**：
```sql
-- 工作日统计（周一到周五）
-- SQLite 的 strftime('%w') 返回 0-6，其中 0=Sunday, 1=Monday, ..., 6=Saturday
-- 所以工作日是 1-5（周一到周五）
SELECT 
    AVG(interval_seconds) AS avg_interval
FROM (
    SELECT 
        e2.date - e1.date AS interval_seconds
    FROM `_entry` e1
    INNER JOIN `_entry` e2 ON e1.id_feed = e2.id_feed 
        AND e2.date = (
            SELECT MIN(date) 
            FROM `_entry` 
            WHERE id_feed = e1.id_feed AND date > e1.date
        )
    WHERE e1.id_feed = {feedID} 
        AND e1.date > {cutoff}
        AND CAST(strftime('%w', datetime(e1.date, 'unixepoch')) AS INTEGER) BETWEEN 1 AND 5
) AS intervals

-- 周末统计（周六和周日）
-- SQLite 的 %w: 0=Sunday, 6=Saturday
SELECT 
    AVG(interval_seconds) AS avg_interval,
    COUNT(*) AS sample_count
FROM (
    SELECT 
        e2.date - e1.date AS interval_seconds
    FROM `_entry` e1
    INNER JOIN `_entry` e2 ON e1.id_feed = e2.id_feed 
        AND e2.date = (
            SELECT MIN(date) 
            FROM `_entry` 
            WHERE id_feed = e1.id_feed AND date > e1.date
        )
    WHERE e1.id_feed = {feedID} 
        AND e1.date > {cutoff}
        AND CAST(strftime('%w', datetime(e1.date, 'unixepoch')) AS INTEGER) IN (0, 6)
) AS intervals
```

**说明**：
- SQLite 的 `strftime('%w', ...)` 返回 0-6，其中：
  - 0 = Sunday（周日）
  - 1 = Monday（周一）
  - 2 = Tuesday（周二）
  - ...
  - 6 = Saturday（周六）
- 工作日：1-5（周一到周五）
- 周末：0 和 6（周日和周六）

## 四、动态 TTL 计算策略

### 4.1 多因素加权计算

**核心公式**：
```
TTL = f(current_time, hour_pattern, interval_pattern, day_pattern)
```

**计算步骤**：

1. **获取当前时间特征**
   - 当前小时（0-23）
   - 当前是工作日还是周末

2. **查询历史模式**
   - 当前时段的平均更新间隔（interval_pattern）
   - 当前时段的更新密度（hour_pattern）
   - 当前是工作日还是周末的平均间隔（day_pattern）

3. **加权计算**
   ```
   base_interval = interval_pattern[current_hour]
   day_adjustment = day_pattern[is_weekend ? 'weekend' : 'weekday']
   hour_weight = hour_pattern[current_hour] / avg_hour_density
   
   dynamic_ttl = base_interval * (1 + day_adjustment) * hour_weight
   ```

4. **边界约束**
   - 最小值：defaultTTL
   - 最大值：maxTTL
   - 平滑处理：避免 TTL 值剧烈波动

### 4.2 智能降级策略

当数据不足时，采用降级策略：

1. **数据充足**（>= 30 条记录，>= 7 天历史）
   - 使用完整的多因素分析

2. **数据中等**（10-29 条记录，或 3-6 天历史）
   - 仅使用时间段分析和基础间隔分析
   - 忽略工作日/周末差异

3. **数据不足**（< 10 条记录，或 < 3 天历史）
   - 回退到当前简单平均算法
   - 或使用 maxTTL

### 4.3 缓存优化

为避免每次计算都执行复杂查询，实现缓存机制：

- 缓存每个 feed 的分析结果（TTL 模式）
- 缓存有效期：1 小时
- 当 feed 更新后，清除对应缓存

## 五、实现方案

### 5.1 数据结构设计（内存中，不修改数据库）

```php
class FeedUpdatePattern {
    // 时间段模式（24 小时）
    public array $hourDensity = [];      // 每个时段的更新密度
    public array $hourInterval = [];      // 每个时段的平均间隔
    
    // 工作日/周末模式
    public float $weekdayInterval = 0;    // 工作日平均间隔
    public float $weekendInterval = 0;     // 周末平均间隔
    public bool $hasWeekendUpdates = false; // 周末是否有更新
    
    // 统计信息
    public int $totalEntries = 0;         // 总条目数
    public int $daysCovered = 0;          // 覆盖天数
    public int $lastAnalysisTime = 0;     // 上次分析时间
}
```

### 5.2 核心方法设计

#### AutoTTLStats::analyzeFeedPattern(int $feedID): FeedUpdatePattern

分析 feed 的更新模式，返回模式对象。

**实现要点**：
1. 查询最近 30 天的条目数据
2. 按小时统计更新密度和间隔
3. 区分工作日和周末统计
4. 计算加权系数

#### AutoTTLStats::getDynamicTTL(int $feedID, int $currentTime = null): int

根据当前时间动态计算 TTL。

**实现要点**：
1. 获取或分析 feed 的更新模式
2. 根据当前时间（小时、工作日/周末）选择对应的间隔
3. 应用加权计算
4. 边界约束和平滑处理

#### AutoTTLStats::getStatsCutoff(): int

保持现有方法，但可以扩展为可配置的统计周期。

### 5.3 修改点清单

#### stats.php

1. **新增类**：`FeedUpdatePattern`
2. **新增方法**：`analyzeFeedPattern()` - 分析更新模式
3. **新增方法**：`getDynamicTTL()` - 动态计算 TTL
4. **修改方法**：`getAdjustedTTL()` - 改为调用 `getDynamicTTL()`
5. **新增方法**：`getHourPattern()` - 获取时间段模式
6. **新增方法**：`getDayPattern()` - 获取工作日/周末模式
7. **新增属性**：`$patternCache` - 模式缓存

#### extension.php

1. **修改方法**：`feedBeforeActualizeHook()` - 使用新的动态 TTL 计算
2. 保持其他逻辑不变

### 5.4 SQL 查询优化与 SQLite 兼容性

考虑到性能，所有复杂查询需要：

1. **索引利用**：确保 `_entry` 表的 `(id_feed, date)` 有索引
2. **查询合并**：尽可能在一个查询中获取多个维度的数据
3. **结果缓存**：避免重复查询相同 feed
4. **SQLite 兼容**：所有查询必须兼容 SQLite（FreshRSS 默认使用 SQLite）

**SQLite 兼容的综合查询（使用 CTE，SQLite 3.8.3+ 支持）**：
```sql
WITH entry_intervals AS (
    SELECT 
        e1.date AS entry_date,
        e2.date - e1.date AS interval_seconds,
        CAST(strftime('%H', datetime(e1.date, 'unixepoch')) AS INTEGER) AS hour,
        CAST(strftime('%w', datetime(e1.date, 'unixepoch')) AS INTEGER) AS day_of_week
    FROM `_entry` e1
    INNER JOIN `_entry` e2 ON e1.id_feed = e2.id_feed 
        AND e2.date = (
            SELECT MIN(date) 
            FROM `_entry` 
            WHERE id_feed = e1.id_feed AND date > e1.date
        )
    WHERE e1.id_feed = {feedID} 
        AND e1.date > {cutoff}
)
SELECT 
    hour,
    day_of_week,
    AVG(interval_seconds) AS avg_interval,
    COUNT(*) AS sample_count,
    CAST(COUNT(*) AS REAL) / COUNT(DISTINCT date(datetime(entry_date, 'unixepoch'))) AS density
FROM entry_intervals
GROUP BY hour, day_of_week
```

**SQLite 兼容的综合查询（不使用 CTE，兼容旧版本）**：
```sql
SELECT 
    CAST(strftime('%H', datetime(e1.date, 'unixepoch')) AS INTEGER) AS hour,
    CAST(strftime('%w', datetime(e1.date, 'unixepoch')) AS INTEGER) AS day_of_week,
    AVG(e2.date - e1.date) AS avg_interval,
    COUNT(*) AS sample_count,
    CAST(COUNT(*) AS REAL) / COUNT(DISTINCT date(datetime(e1.date, 'unixepoch'))) AS density
FROM `_entry` e1
INNER JOIN `_entry` e2 ON e1.id_feed = e2.id_feed 
    AND e2.date = (
        SELECT MIN(date) 
        FROM `_entry` 
        WHERE id_feed = e1.id_feed AND date > e1.date
    )
WHERE e1.id_feed = {feedID} 
    AND e1.date > {cutoff}
GROUP BY hour, day_of_week
HAVING sample_count >= 2
```

**SQLite 日期时间函数对照表**：

| MySQL/PostgreSQL | SQLite | 说明 |
|-----------------|--------|------|
| `FROM_UNIXTIME(timestamp)` | `datetime(timestamp, 'unixepoch')` | Unix 时间戳转日期时间 |
| `HOUR(datetime)` | `CAST(strftime('%H', datetime) AS INTEGER)` | 提取小时（0-23） |
| `DAYOFWEEK(datetime)` | `CAST(strftime('%w', datetime) AS INTEGER)` | 星期几（0=周日, 1=周一, ..., 6=周六） |
| `DATE(datetime)` | `date(datetime)` | 提取日期部分 |
| `COUNT(*) / COUNT(...)` | `CAST(COUNT(*) AS REAL) / COUNT(...)` | 确保浮点数除法 |

**实现建议**：
- 优先使用 CTE 版本（代码更清晰），但需要检测 SQLite 版本
- 如果 SQLite 版本 < 3.8.3，使用非 CTE 版本
- 或者统一使用非 CTE 版本以确保最大兼容性

## 六、平滑处理与边界情况

### 6.1 TTL 值平滑

避免 TTL 值剧烈波动：

```php
// 上次计算的 TTL
$previousTTL = $this->getCachedTTL($feedID);

// 新计算的 TTL
$newTTL = $this->calculateDynamicTTL($feedID);

// 平滑处理：新值 = 旧值 * 0.7 + 新值 * 0.3
$smoothedTTL = (int)($previousTTL * 0.7 + $newTTL * 0.3);
```

### 6.2 边界情况处理

1. **无历史数据**
   - 使用 maxTTL

2. **数据过少**
   - 使用简单平均，但设置最小样本数阈值

3. **更新模式突然变化**
   - 检测异常值（如间隔突然增大 3 倍以上）
   - 使用平滑处理避免剧烈变化

4. **时区问题**
   - 使用服务器时区进行分析
   - 确保时间计算的一致性

## 七、性能考虑

### 7.1 查询优化

- **SQLite 兼容性优先**：所有查询必须兼容 SQLite
- **CTE 使用**：SQLite 3.8.3+ 支持 CTE，但为兼容性可提供非 CTE 版本
- **限制统计周期**：保持 30 天，减少查询数据量
- **添加适当的 WHERE 条件过滤**：利用索引加速查询
- **避免复杂函数**：尽量在 PHP 端处理时间计算，减少 SQL 函数调用

### 7.2 缓存策略

- 内存缓存：使用静态变量存储模式分析结果
- 缓存键：`feed_pattern_{feedID}`
- 缓存失效：feed 更新后清除，或 1 小时后自动失效

### 7.3 延迟计算

- 不在每次 `feedBeforeActualizeHook` 时都重新分析
- 仅在缓存失效或首次访问时分析
- 分析结果可复用多次

## 八、测试建议

### 8.1 测试场景

1. **高频更新 feed**（每小时多次更新）
   - 验证在活跃时段 TTL 较短
   - 验证在非活跃时段 TTL 较长

2. **工作日更新 feed**（仅工作日更新）
   - 验证工作日 TTL 正常
   - 验证周末 TTL 延长

3. **低频更新 feed**（每天 1-2 次）
   - 验证 TTL 接近实际更新间隔

4. **不规则更新 feed**（更新时间不固定）
   - 验证平滑处理有效
   - 验证不会出现极端 TTL 值

### 8.2 验证指标

- TTL 值的合理性（在 defaultTTL 和 maxTTL 之间）
- 更新时机的准确性（在预期更新时间附近）
- 性能影响（查询时间 < 100ms）
- 内存使用（缓存大小可控）

## 九、实施步骤

### 阶段一：核心算法实现
1. 实现 `FeedUpdatePattern` 类
2. 实现 `analyzeFeedPattern()` 方法
3. 实现基础的时间段分析查询

### 阶段二：动态计算
1. 实现 `getDynamicTTL()` 方法
2. 实现工作日/周末分析
3. 实现加权计算逻辑

### 阶段三：优化与缓存
1. 实现缓存机制
2. 实现平滑处理
3. 优化 SQL 查询性能

### 阶段四：测试与调优
1. 测试各种 feed 类型
2. 性能测试
3. 边界情况测试
4. 根据测试结果调优参数

## 十、配置扩展（可选）

未来可以考虑添加以下配置项：

- `auto_ttl_analysis_period`: 统计分析周期（默认 30 天）
- `auto_ttl_smoothing_factor`: 平滑系数（默认 0.3）
- `auto_ttl_min_samples`: 最小样本数（默认 10）
- `auto_ttl_enable_day_pattern`: 是否启用工作日/周末分析（默认 true）
- `auto_ttl_enable_hour_pattern`: 是否启用时间段分析（默认 true）

## 十一、SQLite 兼容性说明

### 11.1 关键差异

FreshRSS 默认使用 SQLite，所有 SQL 查询必须兼容 SQLite：

1. **日期时间函数**：
   - SQLite 使用 `datetime(timestamp, 'unixepoch')` 而非 `FROM_UNIXTIME()`
   - SQLite 使用 `strftime()` 提取时间组件

2. **类型转换**：
   - SQLite 需要显式 `CAST(... AS INTEGER)` 或 `CAST(... AS REAL)`
   - 除法运算需要确保至少一个操作数为 REAL 类型

3. **星期几表示**：
   - SQLite 的 `strftime('%w')` 返回 0-6（0=周日）
   - 需要转换为工作日/周末判断逻辑

### 11.2 实现策略

**方案 A：纯 SQL 实现（推荐）**
- 所有时间计算在 SQL 中完成
- 使用 SQLite 兼容的函数
- 优点：减少 PHP 端处理
- 缺点：SQL 较复杂

**方案 B：混合实现（更灵活）**
- SQL 仅查询原始数据（date 字段）
- PHP 端进行时间解析和分组
- 优点：代码更清晰，易于维护
- 缺点：需要传输更多数据

**推荐使用方案 B**，原因：
- 代码可读性更好
- 更容易调试和维护
- 可以复用 PHP 的 DateTime 类
- 减少 SQL 复杂度，提高兼容性

### 11.3 PHP 实现示例

```php
// 获取原始数据
$sql = "SELECT date FROM `_entry` WHERE id_feed = {$feedID} AND date > {$cutoff} ORDER BY date";
$stm = $this->pdo->query($sql);
$entries = $stm->fetchAll(PDO::FETCH_COLUMN);

// PHP 端处理时间分析
$hourStats = [];
$dayStats = ['weekday' => [], 'weekend' => []];

foreach ($entries as $i => $timestamp) {
    $dt = new DateTime('@' . $timestamp);
    $hour = (int)$dt->format('G');  // 0-23
    $dayOfWeek = (int)$dt->format('w');  // 0=Sunday, 6=Saturday
    
    // 统计小时
    if (!isset($hourStats[$hour])) {
        $hourStats[$hour] = ['count' => 0, 'intervals' => []];
    }
    $hourStats[$hour]['count']++;
    
    // 计算间隔（如果有下一个条目）
    if (isset($entries[$i + 1])) {
        $interval = $entries[$i + 1] - $timestamp;
        $hourStats[$hour]['intervals'][] = $interval;
        
        // 按工作日/周末分类
        $isWeekend = ($dayOfWeek == 0 || $dayOfWeek == 6);
        $key = $isWeekend ? 'weekend' : 'weekday';
        $dayStats[$key][] = $interval;
    }
}

// 计算平均值
foreach ($hourStats as $hour => &$stats) {
    $stats['avg_interval'] = count($stats['intervals']) > 0 
        ? array_sum($stats['intervals']) / count($stats['intervals']) 
        : 0;
}
```

## 十二、总结

本方案通过分析 feed 的更新时间集中时段、更新间隔动态差异和工作日/周末特征，实现了更加智能和动态的 TTL 计算。核心优势：

1. **更精准**：根据历史模式预测最佳更新时机
2. **更智能**：区分不同时间段的更新特征
3. **更高效**：减少不必要的更新请求
4. **更稳定**：通过平滑处理避免剧烈波动

同时保持：
- **不修改数据库结构**：仅使用现有 `_entry` 表数据
- **向后兼容**：数据不足时回退到原有算法
- **性能可控**：通过缓存和查询优化保证性能
- **SQLite 兼容**：所有 SQL 查询完全兼容 SQLite，确保在 FreshRSS 默认环境下正常运行

