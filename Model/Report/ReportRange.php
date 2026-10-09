<?php
/**
 * Meetanshi AIReporting — date range filter of the pre-built report pages
 *
 * Today, This Week, This Month, This Year or a custom range, resolved to store-local
 * [start, end) dates, with the label shown under the filter.
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Model\Report;

class ReportRange
{
    public const DEFAULT_RANGE = 'year';

    private const LABELS = [
        'today'  => 'Today',
        'week'   => 'This Week',
        'month'  => 'This Month',
        'year'   => 'This Year',
        'custom' => 'Custom Range',
    ];

    public function __construct(
        private readonly ReportContext $context
    ) {
    }

    /**
     * Preset ranges for the filter buttons ("This Year (2026)"); the custom range has its own picker.
     *
     * @return array<string, string>
     */
    public function getPresetLabels(): array
    {
        $labels = [];
        foreach (self::LABELS as $key => $label) {
            if ($key !== 'custom') {
                $labels[$key] = (string) __($label);
            }
        }
        $labels['year'] .= ' (' . $this->context->today()->format('Y') . ')';

        return $labels;
    }

    /**
     * Resolve a range key (unknown keys fall back to This Year) to local dates.
     *
     * @return array{key: string, start: \DateTimeImmutable, end: \DateTimeImmutable, days: int, label: string}
     *         end is exclusive (the day after the last day)
     */
    public function resolve(string $range, ?string $customStart = null, ?string $customEnd = null): array
    {
        $range    = isset(self::LABELS[$range]) ? $range : self::DEFAULT_RANGE;
        $today    = $this->context->today();
        $tomorrow = $today->modify('+1 day');

        switch ($range) {
            case 'today':
                [$start, $end] = [$today, $tomorrow];
                break;
            case 'week':
                [$start, $end] = [$today->modify('monday this week'), $tomorrow];
                break;
            case 'month':
                [$start, $end] = [$today->modify('first day of this month'), $tomorrow];
                break;
            case 'custom':
                $start = $this->context->parseLocalDate($customStart) ?? $today->modify('-29 days');
                $last  = $this->context->parseLocalDate($customEnd) ?? $today;
                if ($start > $last) {
                    [$start, $last] = [$last, $start];
                }
                $end = $last->modify('+1 day');
                break;
            case 'year':
            default:
                [$start, $end] = [$today->setDate((int) $today->format('Y'), 1, 1), $tomorrow];
        }

        return [
            'key'   => $range,
            'start' => $start,
            'end'   => $end,
            'days'  => (int) $start->diff($end)->days,
            'label' => $this->getLabel($range, $start, $end->modify('-1 day')),
        ];
    }

    /**
     * The resolved range for the page script: key, inclusive Y-m-d dates, label and length in days.
     */
    public function toArray(array $resolved): array
    {
        return [
            'key'   => $resolved['key'],
            'start' => $resolved['start']->format('Y-m-d'),
            'end'   => $resolved['end']->modify('-1 day')->format('Y-m-d'),
            'label' => $resolved['label'],
            'days'  => $resolved['days'],
        ];
    }

    /**
     * "This Year (Jan 1 – Oct 8, 2026)"
     */
    private function getLabel(string $range, \DateTimeImmutable $start, \DateTimeImmutable $last): string
    {
        if ($start->format('Y-m-d') === $last->format('Y-m-d')) {
            $dates = $start->format('M j, Y');
        } elseif ($start->format('Y') === $last->format('Y')) {
            $dates = $start->format('M j') . ' – ' . $last->format('M j, Y');
        } else {
            $dates = $start->format('M j, Y') . ' – ' . $last->format('M j, Y');
        }

        return __(self::LABELS[$range]) . ' (' . $dates . ')';
    }
}
