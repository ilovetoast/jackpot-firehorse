# Trending Sort Algorithm

## Overview

The "Trending" sort option surfaces assets that are **hot right now** — recent downloads and views weighted more heavily than older activity.

## Algorithm

- **Exponential decay**: Each metric event (download or view) contributes `e^(-λ × days_ago)` to the trending score.
- **Decay rate λ = 0.1**: ~7-day half-life (activity from a week ago counts ~50%).
- **14-day window**: Only metrics from the last 14 days are included.
- **Fallback**: Assets with no activity in 14 days get score 0 and sort by `created_at` as tiebreaker.

## Industry Practice

- **Reddit Hot**: `score = (upvotes - 1) / (age + 2)^1.5`
- **Hacker News**: Time decay on points
- **Our approach**: Simpler exponential decay; combines downloads + views; no separate "age penalty" (new assets with few metrics still rank by recency of those metrics).

## Fallback for Inactive Assets

Assets not used in 1–2 weeks get `trending_score = 0`. They appear at the end when sorting "Trending" (desc). Secondary sort by `created_at` keeps order deterministic.

## Future Enhancements

- **Cached aggregates**: Pre-compute weekly counts in `metric_aggregates`; apply decay in application layer for faster queries.
- **Tunable decay**: Make λ configurable per tenant.
- **"Might interest you"**: For assets with 0 score, consider category-based or similarity-based recommendations.
