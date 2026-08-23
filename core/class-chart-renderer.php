<?php
namespace Pylon\Core;
defined('ABSPATH') || exit;
/**
 * Pure-SVG chart renderer — zero external dependencies.
 * Used by Pulse, GSC, SEO Audit, Rank Tracker and Analytics admin pages.
 */
final class ChartRenderer {

    /* -----------------------------------------------------------------
     *  LINE / AREA CHART
     * --------------------------------------------------------------- */

    /**
     * Multi-series line chart (optional gradient area fill under the first series).
     *
     * @param array $series  List of ['name' => string, 'color' => string, 'data' => float[]].
     * @param array $opts    width, height, padding, y_min, y_max, y_ticks, x_labels (string[]),
     *                       fill (bool), legend (bool), x_label_every (int), format (callable).
     */
    public static function line(array $series, array $opts = []): string {
        $width    = (int) ($opts['width'] ?? 560);
        $height   = (int) ($opts['height'] ?? 200);
        $pad      = (int) ($opts['padding'] ?? 40);
        $y_ticks  = (int) ($opts['y_ticks'] ?? 4);
        $fill     = (bool) ($opts['fill'] ?? true);
        $legend   = (bool) ($opts['legend'] ?? true);
        $x_labels = (array) ($opts['x_labels'] ?? []);
        $x_every  = max(1, (int) ($opts['x_label_every'] ?? 1));
        $format   = $opts['format'] ?? null;

        if (empty($series) || empty($series[0]['data'])) {
            return self::empty_state($opts['empty'] ?? '');
        }

        $all = [];
        foreach ($series as $s) {
            foreach ((array) $s['data'] as $v) {
                $all[] = (float) $v;
            }
        }

        $y_min = isset($opts['y_min']) ? (float) $opts['y_min'] : 0;
        $y_max = isset($opts['y_max']) ? (float) $opts['y_max'] : (max($all) ?: 1) * 1.1;
        if ($y_max <= $y_min) {
            $y_max = $y_min + 1;
        }
        $invert = (bool) ($opts['invert'] ?? false);

        $inner_w = $width - $pad * 2;
        $inner_h = $height - $pad * 2;
        $n       = count($series[0]['data']);
        $xs      = [];
        $point_n = max($n - 1, 1);
        for ($i = 0; $i < $n; $i++) {
            $xs[] = $pad + ($i / $point_n) * $inner_w;
        }

        $y_for = function (float $v) use ($pad, $inner_h, $y_min, $y_max, $invert): float {
            $t = ($v - $y_min) / ($y_max - $y_min);
            $y = $pad + $inner_h - $t * $inner_h;
            return $invert ? $pad + ($inner_h - ($y - $pad)) : $y;
        };

        $svg = '<svg viewBox="0 0 ' . $width . ' ' . $height . '" xmlns="http://www.w3.org/2000/svg" style="width:100%;height:auto;display:block;">';

        // Gridlines + Y labels.
        for ($t = 0; $t <= $y_ticks; $t++) {
            $ratio = $t / $y_ticks;
            $val   = $y_max - $ratio * ($y_max - $y_min);
            $y     = $pad + $ratio * $inner_h;
            $svg  .= '<line x1="' . $pad . '" y1="' . self::r($y) . '" x2="' . ($width - $pad) . '" y2="' . self::r($y) . '" stroke="var(--pylon-gray-200, #e2e8f0)" stroke-width="1"/>';
            $label = $format ? (string) call_user_func($format, $val) : self::r($val, 0);
            $svg  .= '<text x="' . ($pad - 6) . '" y="' . self::r($y + 3) . '" font-size="10" fill="var(--pylon-gray-400, #94a3b8)" text-anchor="end">' . esc_html($label) . '</text>';
        }

        // X labels.
        if ($x_labels) {
            for ($i = 0; $i < $n; $i++) {
                if ($i % $x_every !== 0 && $i !== $n - 1) continue;
                $label = $x_labels[$i] ?? '';
                if ($label === '') continue;
                $svg .= '<text x="' . self::r($xs[$i]) . '" y="' . ($height - 12) . '" font-size="10" fill="var(--pylon-gray-400, #94a3b8)" text-anchor="middle">' . esc_html($label) . '</text>';
            }
        }

        // Series.
        $first = true;
        foreach ($series as $idx => $s) {
            $color = $s['color'] ?? (self::PALETTE[$idx % count(self::PALETTE)]);
            $data  = (array) $s['data'];
            $pts   = [];
            $dots  = '';
            foreach ($data as $i => $v) {
                $pts[] = self::r($xs[$i]) . ',' . self::r($y_for((float) $v));
            }
            $path = 'M' . implode(' L', $pts);
            if (count($pts) === 1) {
                $dots = '<circle cx="' . self::r($xs[0]) . '" cy="' . self::r($y_for((float) $data[0])) . '" r="3.5" fill="' . esc_attr($color) . '"/>';
            } else {
                $dots = '';
                foreach ($pts as $k => $p) {
                    $xy = explode(',', $p);
                    $dots .= '<circle cx="' . $xy[0] . '" cy="' . $xy[1] . '" r="3" fill="#fff" stroke="' . esc_attr($color) . '" stroke-width="2"/>';
                }
            }

            if ($fill && count($pts) > 1 && $first) {
                $area  = 'M' . implode(' L', $pts)
                       . ' L' . self::r($xs[$n - 1]) . ',' . ($pad + $inner_h)
                       . ' L' . self::r($xs[0]) . ',' . ($pad + $inner_h) . ' Z';
                $gid   = 'pylon-chart-grad-' . $idx . '-' . wp_rand(1000, 9999);
                $svg  .= '<defs><linearGradient id="' . $gid . '" x1="0" y1="0" x2="0" y2="1">'
                       . '<stop offset="0%" stop-color="' . esc_attr($color) . '" stop-opacity="0.25"/>'
                       . '<stop offset="100%" stop-color="' . esc_attr($color) . '" stop-opacity="0.02"/>'
                       . '</linearGradient></defs>'
                       . '<path d="' . $area . '" fill="url(#' . $gid . ')"/>';
            }
            $svg .= '<path d="' . $path . '" fill="none" stroke="' . esc_attr($color) . '" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"/>' . $dots;
            $first = false;
        }

        // Legend.
        if ($legend && count($series) > 1) {
            $lx = $pad;
            $ly = 14;
            foreach ($series as $idx => $s) {
                $color = $s['color'] ?? (self::PALETTE[$idx % count(self::PALETTE)]);
                $svg  .= '<rect x="' . $lx . '" y="' . ($ly - 7) . '" width="9" height="9" rx="2" fill="' . esc_attr($color) . '"/>'
                       . '<text x="' . ($lx + 13) . '" y="' . $ly . '" font-size="10" fill="var(--pylon-gray-500, #64748b)">' . esc_html($s['name'] ?? '') . '</text>';
                $lx += 13 + 16 + (strlen($s['name'] ?? '') * 5.5);
            }
        }

        $svg .= '</svg>';
        return $svg;
    }

    /* -----------------------------------------------------------------
     *  BAR CHART
     * --------------------------------------------------------------- */

    /**
     * Vertical bar chart with optional horizontal gridlines.
     *
     * @param array $items  List of ['label' => string, 'value' => float, 'color' => string].
     * @param array $opts   width, height, padding, max, x_label_every, format.
     */
    public static function bars(array $items, array $opts = []): string {
        if (empty($items)) {
            return self::empty_state($opts['empty'] ?? '');
        }

        $width    = (int) ($opts['width'] ?? 560);
        $height   = (int) ($opts['height'] ?? 200);
        $pad      = (int) ($opts['padding'] ?? 32);
        $x_every  = max(1, (int) ($opts['x_label_every'] ?? 1));
        $format   = $opts['format'] ?? null;

        $max_val  = isset($opts['max']) ? (float) $opts['max'] : 0;
        foreach ($items as $it) {
            $max_val = max($max_val, (float) $it['value']);
        }
        $max_val  = $max_val ?: 1;

        $inner_w  = $width - $pad * 2;
        $inner_h  = $height - $pad - 10;
        $n        = count($items);
        $slot     = $inner_w / $n;
        $bar_w    = max(2, $slot * 0.6);

        $svg = '<svg viewBox="0 0 ' . $width . ' ' . $height . '" xmlns="http://www.w3.org/2000/svg" style="width:100%;height:auto;display:block;">';

        $ticks = 4;
        for ($t = 0; $t <= $ticks; $t++) {
            $ratio = $t / $ticks;
            $val   = $max_val * $ratio;
            $y     = $pad + (1 - $ratio) * $inner_h;
            $svg  .= '<line x1="' . $pad . '" y1="' . self::r($y) . '" x2="' . ($width - $pad) . '" y2="' . self::r($y) . '" stroke="var(--pylon-gray-200, #e2e8f0)" stroke-width="1"/>';
            $label = $format ? (string) call_user_func($format, $val) : self::r($val, 0);
            $svg  .= '<text x="' . ($pad - 6) . '" y="' . self::r($y + 3) . '" font-size="10" fill="var(--pylon-gray-400, #94a3b8)" text-anchor="end">' . esc_html($label) . '</text>';
        }

        foreach ($items as $i => $it) {
            $v = max(0, (float) $it['value']);
            $bh = $v / $max_val * $inner_h;
            $x = $pad + $i * $slot + ($slot - $bar_w) / 2;
            $y = $pad + ($inner_h - $bh);
            $color = $it['color'] ?? 'var(--pylon-primary, #6366f1)';
            $svg  .= '<rect x="' . self::r($x) . '" y="' . self::r($y) . '" width="' . self::r($bar_w) . '" height="' . self::r($bh) . '" rx="3" fill="' . esc_attr($color) . '" opacity="0.85">'
                   . '<title>' . esc_html($it['label'] ?? '') . ': ' . self::r($v) . '</title></rect>';
            if ($i % $x_every === 0 || $i === $n - 1) {
                $label = $it['label'] ?? '';
                $svg  .= '<text x="' . self::r($x + $bar_w / 2) . '" y="' . ($height - 4) . '" font-size="10" fill="var(--pylon-gray-400, #94a3b8)" text-anchor="middle">' . esc_html($label) . '</text>';
            }
        }

        $svg .= '</svg>';
        return $svg;
    }

    /* -----------------------------------------------------------------
     *  DONUT CHART
     * --------------------------------------------------------------- */

    /**
     * Donut chart with legend and center label.
     *
     * @param array $segments  List of ['label' => string, 'value' => float, 'color' => string].
     * @param array $opts      size, thickness, center_label, center_value.
     */
    public static function donut(array $segments, array $opts = []): string {
        $total = 0;
        foreach ($segments as $s) {
            $total += max(0, (float) $s['value']);
        }
        if ($total <= 0) {
            return self::empty_state($opts['empty'] ?? '');
        }

        $size      = (int) ($opts['size'] ?? 180);
        $thickness = (int) ($opts['thickness'] ?? 26);
        $cx        = $size / 2;
        $cy        = $size / 2;
        $r         = ($size - $thickness) / 2;
        $circ      = 2 * M_PI * $r;
        $svg       = '<svg viewBox="0 0 ' . $size . ' ' . $size . '" xmlns="http://www.w3.org/2000/svg" style="width:100%;max-width:' . $size . 'px;height:auto;display:block;">';

        $rotation = -90;
        foreach ($segments as $i => $s) {
            $v       = max(0, (float) $s['value']);
            if ($v <= 0) continue;
            $frac    = $v / $total;
            $sweep   = $frac * $circ;
            $color   = $s['color'] ?? (self::PALETTE[$i % count(self::PALETTE)]);
            $svg    .= '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . $r . '" fill="none" stroke="' . esc_attr($color) . '" stroke-width="' . $thickness . '" stroke-dasharray="' . self::r($sweep) . ' ' . self::r($circ) . '" stroke-dashoffset="' . self::r(-$sweep / 2) . '" transform="rotate(' . self::r($rotation) . ' ' . $cx . ' ' . $cy . ')"><title>' . esc_html($s['label'] ?? '') . ': ' . self::r($v) . '</title></circle>';
            $rotation += $frac * 360;
        }

        if (!empty($opts['center_label']) || isset($opts['center_value'])) {
            $svg .= '<text x="' . $cx . '" y="' . ($cy - 4) . '" font-size="20" font-weight="700" fill="#1e293b" text-anchor="middle">' . esc_html((string) ($opts['center_value'] ?? '')) . '</text>';
            $svg .= '<text x="' . $cx . '" y="' . ($cy + 16) . '" font-size="10" fill="#64748b" text-anchor="middle">' . esc_html((string) ($opts['center_label'] ?? '')) . '</text>';
        }

        $svg .= '</svg>';
        return $svg;
    }

    /* -----------------------------------------------------------------
     *  HORIZONTAL BAR LIST (issues / breakdown)
     * --------------------------------------------------------------- */

    /**
     * Horizontal progress-style bars with labels.
     *
     * @param array $items  List of ['label' => string, 'value' => float, 'color' => string, 'hint' => string].
     * @param array $opts   max, height.
     */
    public static function hbars(array $items, array $opts = []): string {
        if (empty($items)) {
            return self::empty_state($opts['empty'] ?? '');
        }

        $max = isset($opts['max']) ? (float) $opts['max'] : 0;
        foreach ($items as $it) {
            $max = max($max, (float) $it['value']);
        }
        $max = $max ?: 1;

        $html = '<div class="pylon-hbars" style="display:flex;flex-direction:column;gap:10px;">';
        foreach ($items as $it) {
            $v     = max(0, (float) $it['value']);
            $pct   = round($v / $max * 100);
            $color = $it['color'] ?? 'var(--pylon-primary, #6366f1)';
            $hint  = $it['hint'] ?? self::r($v);
            $html .= '<div>';
            $html .= '<div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:3px;">'
                   . '<span style="font-weight:500;color:#334155;">' . esc_html($it['label'] ?? '') . '</span>'
                   . '<span style="color:#475569;font-weight:600;">' . esc_html((string) $hint) . '</span></div>';
            $html .= '<div style="height:14px;background:var(--pylon-gray-100, #f1f5f9);border-radius:7px;overflow:hidden;">'
                   . '<div style="width:' . $pct . '%;height:100%;background:' . esc_attr($color) . ';border-radius:7px;transition:width 0.6s ease;"></div>'
                   . '</div>';
            $html .= '</div>';
        }
        $html .= '</div>';
        return $html;
    }

    /* -----------------------------------------------------------------
     *  SPARKLINE
     * --------------------------------------------------------------- */

    public static function sparkline(array $values, array $opts = []): string {
        if (count($values) < 1) {
            return '<span style="color:#cbd5e1;font-size:11px;">' . esc_html__('—', 'pylon-seo') . '</span>';
        }
        $width  = (int) ($opts['width'] ?? 80);
        $height = (int) ($opts['height'] ?? 24);
        $color  = $opts['color'] ?? 'var(--pylon-primary, #6366f1)';
        $min    = min($values);
        $max    = max($values);
        $range  = ($max - $min) ?: 1;
        $n      = count($values);
        $invert = (bool) ($opts['invert'] ?? false);
        $pts    = [];
        for ($i = 0; $i < $n; $i++) {
            $x = $width * ($n === 1 ? 0.5 : $i / ($n - 1));
            $y = $height - (($values[$i] - $min) / $range) * ($height - 4) - 2;
            if ($invert) {
                $y = ($height - 2) - ($y - 2);
            }
            $pts[] = self::r($x) . ',' . self::r($y);
        }
        return '<svg viewBox="0 0 ' . $width . ' ' . $height . '" xmlns="http://www.w3.org/2000/svg" style="width:' . $width . 'px;height:' . $height . 'px;display:block;">'
             . '<polyline points="' . implode(' ', $pts) . '" fill="none" stroke="' . esc_attr($color) . '" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>'
             . '</svg>';
    }

    /* -----------------------------------------------------------------
     *  HELPERS
     * --------------------------------------------------------------- */

    /**
     * KSES allow-list covering every element/attribute this renderer emits.
     * Use with wp_kses() when printing chart markup.
     */
    public static function allowed_html(): array {
        return [
            'svg' => ['viewbox' => true, 'xmlns' => true, 'style' => true, 'width' => true, 'height' => true, 'fill' => true],
            'defs' => [],
            'lineargradient' => ['id' => true, 'x1' => true, 'y1' => true, 'x2' => true, 'y2' => true, 'gradientunits' => true],
            'stop' => ['offset' => true, 'stop-color' => true, 'stop-opacity' => true],
            'path' => ['d' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linejoin' => true, 'stroke-linecap' => true, 'opacity' => true],
            'circle' => ['cx' => true, 'cy' => true, 'r' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-dasharray' => true, 'stroke-dashoffset' => true, 'transform' => true, 'opacity' => true],
            'rect' => ['x' => true, 'y' => true, 'width' => true, 'height' => true, 'rx' => true, 'ry' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'opacity' => true],
            'line' => ['x1' => true, 'y1' => true, 'x2' => true, 'y2' => true, 'stroke' => true, 'stroke-width' => true],
            'polyline' => ['points' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linejoin' => true, 'stroke-linecap' => true],
            'text' => ['x' => true, 'y' => true, 'font-size' => true, 'font-weight' => true, 'fill' => true, 'text-anchor' => true],
            'title' => [],
            'div' => ['class' => true, 'style' => true],
            'span' => ['class' => true, 'style' => true],
        ];
    }

    private const PALETTE = ['#6366f1', '#22c55e', '#f59e0b', '#ef4444', '#0ea5e9', '#a855f7'];

    private static function r(float $v, int $dec = 1): string {
        return rtrim(rtrim(number_format($v, $dec, '.', ''), '0'), '.');
    }

    private static function empty_state(string $message): string {
        $message = $message ?: __('No data yet.', 'pylon-seo');
        return '<div style="color:#94a3b8;text-align:center;padding:24px;font-size:13px;">' . esc_html($message) . '</div>';
    }
}
