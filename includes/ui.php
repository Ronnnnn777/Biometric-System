<?php
/**
 * Shared UI primitives.
 *
 * Buttons and cards use the component classes defined in css/tailwind-input.css
 * so the visual language stays consistent across the admin and teacher portals.
 */
require_once __DIR__ . '/icons.php';

/**
 * Compact top bar: hamburger, breadcrumb/page name, page actions, and clock.
 *
 * @param array<int, array{label: string, href?: string}> $crumbs
 */
function ui_topbar(array $crumbs, string $subtitle = '', string $actions = '', string $menuAttr = ''): void
{
    $clockFallback = date('M j, Y, g:i A');
    ?>
    <header
        class="app-topbar sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-slate-200 bg-white/95 px-4 backdrop-blur lg:px-6">
        <button <?= $menuAttr ?> type="button"
            class="rounded-lg border border-slate-200 p-2 text-slate-600 transition hover:bg-slate-50 hover:text-slate-900"
            aria-label="Toggle navigation" aria-expanded="true"
            title="Hide navigation"><?= icon('menu') ?></button>

        <div class="min-w-0 flex-1">
            <?php
            // Every breadcrumb level is rendered as a link. The current page falls back
            // to its own URL so the hierarchy never contains a dead-end label.
            $crumbCount = count($crumbs);
            $currentUrl = $_SERVER['REQUEST_URI'] ?? '#';
            ?>
            <nav aria-label="Breadcrumb" class="flex items-center gap-1.5 text-sm">
                <?php foreach ($crumbs as $index => $crumb): ?>
                    <?php
                    $isCurrent = $index === $crumbCount - 1;
                    $href = trim((string) ($crumb['href'] ?? '')) ?: $currentUrl;
                    ?>
                    <?php if ($index > 0): ?><span class="text-slate-300" aria-hidden="true">/</span><?php endif; ?>
                    <a href="<?= htmlspecialchars($href) ?>"
                        class="shrink-0 transition <?= $isCurrent ? 'truncate font-semibold text-slate-900' : 'text-slate-500 hover:text-slate-900' ?>"
                        <?= $isCurrent ? 'aria-current="page"' : '' ?>><?= htmlspecialchars($crumb['label']) ?></a>
                <?php endforeach; ?>
            </nav>
            <?php if ($subtitle !== ''): ?>
                <p class="hidden truncate text-xs text-slate-500 sm:block"><?= htmlspecialchars($subtitle) ?></p>
            <?php endif; ?>
        </div>

        <div class="ml-auto flex items-center gap-2">
            <?= $actions ?>
            <div
                class="hidden items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-semibold text-slate-600 sm:flex">
                <?= icon('calendar') ?><span id="clock"><?= htmlspecialchars($clockFallback) ?></span>
            </div>
        </div>
    </header>
    <?php
}

/**
 * Render a button or link with the shared button classes.
 *
 * @param array{variant?: string, size?: string, icon?: string, href?: string, type?: string, attrs?: array<string, string>} $options
 */
function ui_button(string $label, array $options = []): string
{
    $variant = $options['variant'] ?? 'primary';
    $size = $options['size'] ?? 'md';
    $icon = $options['icon'] ?? null;
    $href = $options['href'] ?? null;
    $type = $options['type'] ?? 'button';
    $attrs = $options['attrs'] ?? [];

    $classes = ['btn', 'btn-' . $variant];
    if ($size === 'sm') {
        $classes[] = 'btn-sm';
    }
    if ($size === 'lg') {
        $classes[] = 'btn-lg';
    }
    if ($icon !== null && $label === '') {
        $classes[] = 'btn-icon';
    }

    $attributeString = '';
    foreach ($attrs as $name => $value) {
        $attributeString .= ' ' . $name . '="' . htmlspecialchars((string) $value, ENT_QUOTES) . '"';
    }

    $inner = ($icon !== null ? icon($icon) : '')
        . ($label !== '' ? '<span>' . htmlspecialchars($label) . '</span>' : '');
    $classAttribute = htmlspecialchars(implode(' ', $classes), ENT_QUOTES);

    if ($href !== null) {
        return '<a href="' . htmlspecialchars($href, ENT_QUOTES) . '" class="' . $classAttribute . '"'
            . $attributeString . '>' . $inner . '</a>';
    }

    return '<button type="' . htmlspecialchars($type, ENT_QUOTES) . '" class="' . $classAttribute . '"'
        . $attributeString . '>' . $inner . '</button>';
}

/**
 * Capsule segmented pill control.
 *
 * Renders the shared pill shell used for grade and quarter selectors. A hidden
 * input mirrors the active value so plain form posts (and progressive
 * enhancement) always receive the current selection. Add `href` to an item to
 * turn its pill into a navigation control; otherwise it behaves as a filter
 * button wired up by js/capsule-control.js.
 *
 * Visual-only items are supported for long selectors: use
 * ['type' => 'label', 'label' => 'JHS'] for a grouping label or
 * ['type' => 'divider'] for a vertical rule. Wrap related items in
 * ['type' => 'group', 'items' => [...]] so they wrap together in fluid mode.
 *
 * @param array<int|string, array{type?: string, value?: string, label?: string, href?: string, items?: array}|string> $items
 * @param array{aria_label?: string, hidden_input?: bool, container_class?: string, active_class?: string, inactive_class?: string, fluid?: bool} $options
 */
function ui_capsule_group(string $name, array $items, string $activeValue, array $options = []): string
{
    $activeClass = (string) ($options['active_class']
        ?? 'rounded-full bg-slate-900 dark:bg-blue-600 text-white font-bold text-xs sm:text-sm px-3.5 py-1.5 shadow-md transition-all shrink-0');
    $inactiveClass = (string) ($options['inactive_class']
        ?? 'rounded-full text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white font-semibold text-xs sm:text-sm px-3.5 py-1.5 transition-all shrink-0');
    // Fluid capsules fill their grid column, wrap naturally, and never create
    // an internal horizontal scrollbar. Pair them with a grid-rows-[auto_1fr]
    // parent when the shell should stretch to match a taller capsule in the row.
    $fluid = (bool) ($options['fluid'] ?? false);
    $containerClass = (string) ($options['container_class']
        ?? ($fluid
            ? 'flex w-full min-w-0 flex-wrap content-center items-center gap-1 rounded-2xl border border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-900/60 p-1.5 min-h-[46px]'
            : 'inline-flex flex-wrap items-center gap-1 rounded-full bg-white dark:bg-slate-900/90 p-1.5 border border-slate-200 dark:border-slate-800 shadow-sm'));
    $ariaLabel = (string) ($options['aria_label'] ?? 'Select an option');
    $hiddenInput = (bool) ($options['hidden_input'] ?? true);
    $activeValue = (string) $activeValue;

    $html = '<div class="' . $containerClass . '" role="group" aria-label="' . htmlspecialchars($ariaLabel, ENT_QUOTES) . '"'
        . ' data-capsule-group="' . htmlspecialchars($name, ENT_QUOTES) . '"'
        . ' data-capsule-value="' . htmlspecialchars($activeValue, ENT_QUOTES) . '"'
        . ' data-capsule-active-class="' . htmlspecialchars($activeClass, ENT_QUOTES) . '"'
        . ' data-capsule-inactive-class="' . htmlspecialchars($inactiveClass, ENT_QUOTES) . '">';

    if ($hiddenInput) {
        $html .= '<input type="hidden" name="' . htmlspecialchars($name, ENT_QUOTES) . '"'
            . ' value="' . htmlspecialchars($activeValue, ENT_QUOTES) . '"'
            . ' data-capsule-input="' . htmlspecialchars($name, ENT_QUOTES) . '">';
    }

    $renderItems = function (array $items) use (&$renderItems, $activeValue, $activeClass, $inactiveClass): string {
        $markup = '';
        foreach ($items as $key => $item) {
            if (is_string($item)) {
                $item = ['value' => (string) $key, 'label' => $item];
            }

            $type = (string) ($item['type'] ?? '');
            if ($type === 'group') {
                $groupItems = is_array($item['items'] ?? null) ? $item['items'] : [];
                $markup .= '<div class="inline-flex flex-wrap items-center gap-1">'
                    . $renderItems($groupItems) . '</div>';
                continue;
            }

            // Non-selectable visual grouping items keep the capsule shell readable
            // when a long selector is split into related ranges (for example JHS
            // and SHS grade bands).
            if ($type === 'label') {
                $markup .= '<span class="px-1 text-[10px] font-black uppercase tracking-wide text-slate-400" aria-hidden="true">'
                    . htmlspecialchars((string) ($item['label'] ?? ''), ENT_QUOTES) . '</span>';
                continue;
            }
            if ($type === 'divider') {
                $markup .= '<span class="mx-1 h-5 w-px shrink-0 bg-slate-300 dark:bg-slate-700" aria-hidden="true"></span>';
                continue;
            }

            $value = (string) ($item['value'] ?? $key);
            $label = (string) ($item['label'] ?? $value);
            $href = trim((string) ($item['href'] ?? ''));
            $isActive = $value === $activeValue;
            $classes = $isActive ? $activeClass : $inactiveClass;
            $attributes = ' data-capsule-item data-value="' . htmlspecialchars($value, ENT_QUOTES) . '"'
                . ' aria-pressed="' . ($isActive ? 'true' : 'false') . '"';

            if ($href !== '') {
                $markup .= '<a href="' . htmlspecialchars($href, ENT_QUOTES) . '" class="' . $classes . '"'
                    . $attributes . ($isActive ? ' aria-current="page"' : '') . '>'
                    . htmlspecialchars($label) . '</a>';
            } else {
                $markup .= '<button type="button" class="' . $classes . '"' . $attributes . '>'
                    . htmlspecialchars($label) . '</button>';
            }
        }

        return $markup;
    };

    return $html . $renderItems($items) . '</div>';
}

/**
 * Card header with an optional subtitle and trailing actions.
 */
function ui_card_header(string $title, string $subtitle = '', string $actions = ''): string
{
    $subtitleMarkup = $subtitle !== ''
        ? '<p class="card-muted mt-0.5">' . htmlspecialchars($subtitle) . '</p>'
        : '';

    return '<div class="card-header"><div class="min-w-0"><h2 class="card-title">'
        . htmlspecialchars($title) . '</h2>' . $subtitleMarkup . '</div>' . $actions . '</div>';
}
