# FreshRSS-AutoTTL-Improved extension

[查看中文版本](README.cn.md)

> **Note**: This is an improved version based on [mgnsk/FreshRSS-AutoTTL](https://github.com/mgnsk/FreshRSS-AutoTTL), with enhanced intelligent dynamic TTL calculation features.

An enhanced FreshRSS extension for intelligent automatic feed refresh TTL calculation. It analyzes feed update patterns by time slots, weekday/weekend differences, and update intervals to dynamically optimize refresh frequency.

## Key Improvements

This version adds the following enhancements over the original:

- **Time Slot Analysis**: Identifies the most active update hours (0-23) for each feed
- **Day of Week Pattern Recognition**: Automatically detects and adapts to different update patterns for each day of the week (Monday through Sunday), based on actual data without assumptions
- **Dynamic Interval Analysis**: Analyzes update interval variations across different time periods
- **Intelligent Degradation**: Falls back to simple averaging when data is insufficient
- **Smoothing Algorithm**: Prevents TTL value fluctuations for stable refresh behavior
- **Enhanced Statistics Dashboard**: Displays detailed analysis results including data quality, active hours, and weekday/weekend patterns

## How It Works

The extension analyzes three key characteristics of feed updates:

1. **Update Time Concentration**: Identifies which hours of the day have the most frequent updates
2. **Dynamic Interval Differences**: Analyzes how update intervals vary across different time periods
3. **Day of Week Features**: Distinguishes update patterns for each day of the week (Monday through Sunday)

Based on this analysis, it calculates a dynamic TTL that adapts to the current time, ensuring feeds are refreshed at optimal intervals.

## TTL Calculation Logic

> **Note on Timezone**: Time analysis uses the timezone configured in FreshRSS user settings. If no timezone is configured in FreshRSS, it falls back to the server's default timezone (PHP's `date_default_timezone_get()`). The extension uses Unix timestamps from the database and converts them to local time using the configured timezone for hour and day-of-week analysis.

### Phase 1: Data Collection and Analysis

1. **Data Retrieval**
   - Fetch feed entry data from the database for the last N days (configurable, default: 30 days)
   - Sort entries chronologically and calculate time intervals between adjacent entries

2. **Data Quality Assessment**
   - **Insufficient data** (< 10 entries): Use simple average interval = (last time - first time) / (entry count - 1)
   - **Sufficient data** (>= 30 entries and >= 7 days): Perform comprehensive multi-dimensional analysis

3. **Pattern Analysis** (when data is sufficient)
   - **Time slot analysis**: Count update frequency and calculate average intervals for each hour (0-23)
   - **Day of week analysis**: Calculate average update intervals for each day of the week (Sunday=0, Monday=1, ..., Saturday=6)
   - **Update density**: Calculate update density for each time slot = update count / days covered

### Phase 2: Dynamic TTL Calculation

When calculating TTL, dynamically compute based on current time:

1. **Base Interval Determination** (`baseInterval`)
   - Prefer using the average interval of the current time slot (if data exists)
   - If current time slot has no data, use the average of all time slots
   - If all time slots have no data, use simple average interval

2. **Day of Week Adjustment**
   - If the current day of week has actual update data, use that day's average interval
   - If the current day of week has no data, use the average of other days with data
   - **Important**: Completely based on actual data, no assumptions (e.g., "weekend update frequency halved")

3. **Hour Density Weighting** (`hourWeight`)
   - Calculate the ratio of current time slot's update density relative to average density
   - Higher density results in lower weight (more frequent updates, shorter TTL)
   - Weight is limited between 0.5 and 2.0

4. **TTL Calculation**
   ```
   dynamicTTL = baseInterval * hourWeight
   ```

### Phase 3: Smoothing and Boundary Constraints

1. **Smoothing**
   - To prevent TTL value fluctuations, use exponential smoothing algorithm
   - New TTL = Old TTL × 0.7 + Newly calculated TTL × 0.3

2. **Boundary Constraints**
   - Minimum value: `defaultTTL` (system default TTL)
   - Maximum value: `maxTTL` (configured maximum TTL)
   - Final TTL value is constrained within this range

### Phase 4: Caching Mechanism

- **Pattern cache**: Analysis results cached for 1 hour to avoid frequent re-analysis
- **TTL cache**: Calculated TTL cached for 5 minutes to improve response speed
- Cache automatically cleared after feed updates to ensure latest data is used

### Calculation Example

Assume a feed's historical data:
- Monday to Friday average update interval: 2 hours
- Saturday and Sunday average update interval: 6 hours
- Current time: Wednesday 14:00 (Wednesday has data, 14:00 time slot has data)

Calculation process:
1. Use Wednesday's average interval: 2 hours = 7200 seconds
2. 14:00 time slot density weighting: assume weight is 1.0
3. TTL = 7200 × 1.0 = 7200 seconds (2 hours)
4. Smoothing (if old value exists)
5. Boundary constraints (ensure within defaultTTL and maxTTL range)

# Configuration

The main configurable values are:

- **Max TTL**: The maximum time-to-live interval for feed updates
- **Statistics table rows**: Number of feeds to display in the statistics table
- **Statistics days**: Number of days to fetch feed entry data from database for analysis (default: 30 days, range: 1-365 days)

Feeds that use the default TTL are updated at an interval between the default and max TTL, dynamically adjusted based on their update patterns.

For example with default TTL of `1h` and max TTL of `1d`, a feed is updated at least once per day but no more often than once per hour, with the exact interval intelligently calculated based on:
- The feed's historical update patterns
- Current time of day (active hours)
- Current day of the week (Monday through Sunday)
- Update interval variations

## Statistics Dashboard

The extension provides a comprehensive statistics dashboard showing:

- **Data Quality**: Whether full analysis is available or limited data mode
- **Simple Avg TTL**: Traditional average TTL calculation (for comparison)
- **Dynamic TTL (Current)**: Real-time calculated TTL based on current time and patterns
- **Active Hours**: Top 3 most active update hours for each feed
- **Day of Week Pattern**: Update intervals for each day of the week (Sun, Mon, Tue, Wed, Thu, Fri, Sat) based on actual data
- **Last Update & Next Update**: Timing information for feed refreshes

![Screenshot FreshRSS-AutoTTL-Improved · Extensions · FreshRSS](https://github.com/user-attachments/assets/66545901-ae62-4153-8ff6-42601cde0e0a)

# Testing

- `docker compose pull`
- `docker compose up`
- open browser at `http://localhost:8080`.

## MySQL credentials

- Host: `mysql`
- Username: `freshrss`
- Password: `freshrss`
- Database: `freshrss`

## PostgreSQL credentials

- Host: `postgres`
- Username: `freshrss`
- Password: `freshrss`
- Database: `freshrss`

To reset, run `docker compose down`.

Run `docker compose exec freshrss php /var/www/FreshRSS/app/actualize_script.php` to run the actualization script manually.

## Credits

- Original extension: [mgnsk/FreshRSS-AutoTTL](https://github.com/mgnsk/FreshRSS-AutoTTL)
- This improved version adds intelligent dynamic TTL calculation with time slot analysis, day-of-week pattern recognition (based on actual data without assumptions), and enhanced statistics visualization.

## License

AGPL-3.0 (same as the original project)
