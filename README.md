# FreshRSS-AutoTTL-Improved extension

> **Note**: This is an improved version based on [mgnsk/FreshRSS-AutoTTL](https://github.com/mgnsk/FreshRSS-AutoTTL), with enhanced intelligent dynamic TTL calculation features.

An enhanced FreshRSS extension for intelligent automatic feed refresh TTL calculation. It analyzes feed update patterns by time slots, weekday/weekend differences, and update intervals to dynamically optimize refresh frequency.

## Key Improvements

This version adds the following enhancements over the original:

- **Time Slot Analysis**: Identifies the most active update hours (0-23) for each feed
- **Weekday/Weekend Pattern Recognition**: Automatically detects and adapts to different update patterns between weekdays and weekends
- **Dynamic Interval Analysis**: Analyzes update interval variations across different time periods
- **Intelligent Degradation**: Falls back to simple averaging when data is insufficient
- **Smoothing Algorithm**: Prevents TTL value fluctuations for stable refresh behavior
- **Enhanced Statistics Dashboard**: Displays detailed analysis results including data quality, active hours, and weekday/weekend patterns

## How It Works

The extension analyzes three key characteristics of feed updates:

1. **Update Time Concentration**: Identifies which hours of the day have the most frequent updates
2. **Dynamic Interval Differences**: Analyzes how update intervals vary across different time periods
3. **Weekday/Weekend Features**: Distinguishes update patterns between weekdays and weekends

Based on this analysis, it calculates a dynamic TTL that adapts to the current time, ensuring feeds are refreshed at optimal intervals.

# Configuration

The main configurable values are:

- **Max TTL**: The maximum time-to-live interval for feed updates
- **Statistics table rows**: Number of feeds to display in the statistics table

Feeds that use the default TTL are updated at an interval between the default and max TTL, dynamically adjusted based on their update patterns.

For example with default TTL of `1h` and max TTL of `1d`, a feed is updated at least once per day but no more often than once per hour, with the exact interval intelligently calculated based on:
- The feed's historical update patterns
- Current time of day (active hours)
- Whether it's a weekday or weekend
- Update interval variations

## Statistics Dashboard

The extension provides a comprehensive statistics dashboard showing:

- **Data Quality**: Whether full analysis is available or limited data mode
- **Simple Avg TTL**: Traditional average TTL calculation (for comparison)
- **Dynamic TTL (Current)**: Real-time calculated TTL based on current time and patterns
- **Active Hours**: Top 3 most active update hours for each feed
- **Weekday/Weekend**: Separate update intervals for weekdays and weekends
- **Last Update & Next Update**: Timing information for feed refreshes

![Screenshot 2024-10-17 at 16-42-11 AutoTTL · Extensions · FreshRSS](https://github.com/user-attachments/assets/ba712811-d65b-4cd7-ba91-c8cba5c40d64)

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
- This improved version adds intelligent dynamic TTL calculation with time slot analysis, weekday/weekend pattern recognition, and enhanced statistics visualization.

## License

AGPL-3.0 (same as the original project)
