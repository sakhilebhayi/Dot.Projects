import dotPreset from './dot-ui/tailwind/tailwind-preset.cjs';

/**
 * The signed-in workspace's Tailwind build (layouts/app.blade.php). It reproduces exactly what the
 * runtime CDN script used to compile in the browser (class-based dark mode, no preflight reset,
 * no plugins), so moving to a built file changes no page's look. The guest and welcome pages keep
 * tailwind.config.js. Both are replaced by the Dot.Projects design system in R-34 P2.
 *
 * @type {import('tailwindcss').Config}
 */
export default {
    // Roboto and the shared type scale (R-52). The preset adds no plugins.
    presets: [dotPreset],
    darkMode: 'class',
    corePlugins: { preflight: false },
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './vendor/laravel/jetstream/**/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],
};
