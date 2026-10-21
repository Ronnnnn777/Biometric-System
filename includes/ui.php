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
            <nav aria-label="Breadcrumb" class="flex items-center gap-1.5 text-sm">
                <?php foreach ($crumbs as $index => $crumb): ?>
                    <?php if ($index > 0): ?><span class="text-slate-300" aria-hidden="true">/</span><?php endif; ?>
                    <?php if (!empty($crumb['href'])): ?>
                        <a href="<?= htmlspecialchars($crumb['href']) ?>"
                            class="shrink-0 text-slate-500 transition hover:text-slate-900"><?= htmlspecialchars($crumb['label']) ?></a>
                    <?php else: ?>
                        <span class="truncate font-semibold text-slate-900"><?= htmlspecialchars($crumb['label']) ?></span>
                    <?php endif; ?>
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
