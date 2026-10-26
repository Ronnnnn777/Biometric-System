<?php
/**
 * Canonical administrator navigation.
 *
 * URLs are relative to /admin because this array is rendered by the admin
 * sidebar. Archived students and teachers are views inside Students and
 * Teachers, not standalone destinations.
 *
 * @return array<int, array{url: string, label: string, key: string, icon: string}>
 */
return [
    ['url' => 'home.php', 'label' => 'Dashboard', 'key' => 'dashboard', 'icon' => 'home'],
    ['url' => 'student-list.php', 'label' => 'Students', 'key' => 'students', 'icon' => 'users'],
    ['url' => 'teacher-list.php', 'label' => 'Teachers', 'key' => 'teachers', 'icon' => 'briefcase'],
    ['url' => 'attendance-log.php', 'label' => 'Attendance logs', 'key' => 'attendance', 'icon' => 'clipboard-list'],
    ['url' => 'school-year-rollover.php', 'label' => 'School year', 'key' => 'rollover', 'icon' => 'calendar'],
    ['url' => 'settings.php', 'label' => 'Settings', 'key' => 'settings', 'icon' => 'settings'],
];
