<?php $currentPage = $currentPage ?? 'home'; ?>
<header class="sticky top-0 z-40 border-b border-slate-200 bg-white/90 backdrop-blur">
    <nav class="mx-auto flex max-w-7xl items-center justify-between px-5 py-4 lg:px-8" aria-label="Main navigation">
        <a href="index.php" class="flex items-center gap-3 font-bold text-slate-900">
            <img src="image/logo.png" alt="" class="h-10 w-10 rounded-xl object-cover shadow-sm">
            <span>Biometric<span class="text-blue-600">.</span><span class="block text-xs font-medium text-slate-500">Attendance system</span></span>
        </a>
        <button type="button" data-site-menu class="rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-700 lg:hidden" aria-expanded="false" aria-controls="site-navigation">Menu</button>
        <div id="site-navigation" data-site-navigation class="absolute left-0 right-0 top-full hidden border-b border-slate-200 bg-white px-5 py-4 shadow-lg lg:static lg:flex lg:items-center lg:gap-7 lg:border-0 lg:bg-transparent lg:p-0 lg:shadow-none">
            <a class="block py-2 text-sm font-semibold <?= $currentPage === 'home' ? 'text-blue-600' : 'text-slate-600 hover:text-blue-600' ?>" href="index.php">Home</a>
            <a class="block py-2 text-sm font-semibold <?= $currentPage === 'features' ? 'text-blue-600' : 'text-slate-600 hover:text-blue-600' ?>" href="features.php">Features</a>
            <a class="block py-2 text-sm font-semibold <?= $currentPage === 'about' ? 'text-blue-600' : 'text-slate-600 hover:text-blue-600' ?>" href="about-us.php">About</a>
            <a class="mt-2 block rounded-lg bg-blue-600 px-4 py-2.5 text-center text-sm font-bold text-white shadow-sm transition hover:bg-blue-700 lg:mt-0" href="admin/login.php">Admin sign in</a>
        </div>
    </nav>
</header>
